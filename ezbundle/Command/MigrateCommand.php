<?php

/**
 * NovaFormBuilder Bundle.
 *
 * @package   Novactive\Bundle\FormBuilderBundle
 *
 * @author    Maxim Strukov <m.strukov@novactive.com>
 * @copyright 2018 Novactive
 * @license   https://github.com/Novactive/NovaFormBuilderBundle/blob/master/LICENSE MIT Licence
 */

declare(strict_types=1);

namespace Novactive\Bundle\eZFormBuilderBundle\Command;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\FetchMode;
use Doctrine\ORM\EntityManagerInterface;
use Novactive\Bundle\eZFormBuilderBundle\Core\FormService;
use Novactive\Bundle\eZFormBuilderBundle\Core\IOService;
use Novactive\Bundle\FormBuilderBundle\Entity\Field;
use Novactive\Bundle\FormBuilderBundle\Entity\Form;
use Novactive\Bundle\FormBuilderBundle\Entity\FormSubmission;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MigrateCommand.
 */
class MigrateCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'novaformbuilder:migrate';

    /**
     * @var IOService
     */
    private $ioService;

    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var FormService
     */
    private $formService;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public const QUESTION_TYPES = ['EmailEntry', 'TextEntry', 'NumberEntry', 'MultipleChoice'];

    /**
     * MigrateCommand constructor.
     */
    public function __construct(IOService $ioService, FormService $formService, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->ioService     = $ioService;
        $this->formService   = $formService;
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Import database from the old one.')
            ->addOption('export', null, InputOption::VALUE_NONE, 'Export from old DB to json files')
            ->addOption('import', null, InputOption::VALUE_NONE, 'Import from json files to new DB')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'Clean the existing data')
            ->setHelp('Run novaformbuilder:migrate export|import|clean');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Update the Database with Custom Novactive Form Builder Tables');

        if ($input->getOption('export')) {
            $this->export();
        } elseif ($input->getOption('import')) {
            $this->import();
        } elseif ($input->getOption('clean')) {
            $this->clean();
        } else {
            $this->io->error('No export or import option found. Run novaformbuilder:migrate --export|--import');
        }
    }

    private function export(): void
    {
        $forms = [];

        $surveys = $this->runQuery(
            'SELECT max(id) as surveyId, contentobject_id FROM ezsurvey GROUP BY contentobject_id ORDER BY surveyId'
        );

        $fieldsCounter = $submissionsCounter = 0;

        foreach ($surveys as $survey) {
            $fields = [];

            $sql = "SELECT * FROM ezsurveyquestion WHERE survey_id = ? 
                    AND `type` IN ('".implode("','", self::QUESTION_TYPES)."') ORDER BY tab_order";

            $questions = $this->runQuery($sql, [$survey['surveyId']]);
            foreach ($questions as $question) {
                if (empty($question['text'])) {
                    continue;
                }
                $type    = $this->convertType($question['type']);
                $options = [];
                if ('Choice' === $type) {
                    $xml     = simplexml_load_string($question['text2']);
                    $choices = [];
                    $counter = 0;
                    foreach ($xml as $option) {
                        ++$counter;
                        $choices[$counter] = ['value' => (string) $option->value, 'weight' => $counter];
                    }
                    switch ($question['num']) {
                        case 1:
                            $choiceType = 'radio';
                            break;
                        case 2:
                            $choiceType = 'radio';
                            break;
                        case 3:
                            $choiceType = 'checkboxes';
                            break;
                        case 4:
                            $choiceType = 'checkboxes';
                            break;
                        case 5:
                            $choiceType = 'dropdown';
                            break;
                        default:
                            $choiceType = 'dropdown';
                    }
                    $options = ['choice_type' => $choiceType, 'choices' => $choices];
                }

                $fields[] = [
                    'name'   => $question['text'], 'required' => (bool) $question['mandatory'],
                    'weight' => (int) $question['tab_order'], 'options' => $options, 'type' => $type,
                ];
            }

            $fieldsCounter += count($fields);

            if (!empty($fields)) {
                $form = ['name' => 'Form_'.$survey['surveyId'], 'maxSubmissions' => null, 'fields' => $fields];
                $this->ioService->saveFile('forms/'.$form['name'].'.json', json_encode($form));
                $forms[] = $form['name'];

                // Get the Survey Results
                $sql = 'SELECT sqr.result_id,sqr.question_id,sq.type,sq.text,sqr.text answer, ';

                $sql .= "CONCAT(sqr.result_id,'-',sqr.question_id) ids, ";
                $sql .= 'COUNT(CONCAT(sqr.result_id,sqr.question_id)) answersCount, sr.tstamp ';
                $sql .= 'FROM ezsurvey s ';
                $sql .= 'JOIN ezsurveyresult sr ON s.id = sr.survey_id ';
                $sql .= 'JOIN ezsurveyquestionresult sqr ON sr.id = sqr.result_id ';
                $sql .= 'JOIN ezsurveyquestion sq ON sqr.question_id = sq.id ';
                $sql .= 'WHERE s.contentobject_id = ? ';
                $sql .= 'GROUP BY ids ';
                $sql .= 'ORDER BY sqr.result_id,sqr.question_id';

                $results     = $this->runQuery($sql, [$survey['contentobject_id']]);
                $submissions = $data = [];
                $resultId    = null;
                $createdDate = 0;
                foreach ($results as $result) {
                    if ($result['result_id'] !== $resultId) {
                        if (!empty($data)) {
                            $submissions[] = ['data' => $data, 'created_at' => $createdDate];
                            $data          = [];
                        }
                        $resultId = $result['result_id'];
                    }
                    $answers = $result['answer'];
                    if ($result['answersCount'] > 1) {
                        $sql     = 'SELECT text FROM ezsurveyquestionresult WHERE result_id = ? AND question_id = ?';
                        $answers = $this->runQuery(
                            $sql,
                            [$result['result_id'], $result['question_id']],
                            FetchMode::COLUMN
                        );
                    }
                    $data[]      = [
                        'name' => $result['text'], 'value' => $answers,
                        'type' => strtolower($this->convertType($result['type'])),
                    ];
                    $createdDate = date('Y-m-d H:i:s', (int) $result['tstamp']);
                }
                if (!empty($data)) {
                    $submissions[] = ['data' => $data, 'created_at' => $createdDate];
                }
                if (!empty($submissions)) {
                    $this->ioService->saveFile('forms/'.$form['name'].'_submissions.json', json_encode($submissions));
                }
                $this->io->writeln(
                    "Exported #{$form['name']} with ".(string) count($fields).' fields and '.
                    (string) count($submissions).' submissions.'
                );

                $submissionsCounter += count($submissions);
            }
        }
        $this->ioService->saveFile('forms/manifest.json', json_encode($forms));
        $this->io->section(
            'Total: '.(string) count($forms).' forms, '.$fieldsCounter.' fields, '.$submissionsCounter.' submissions.'
        );

        $this->io->success('Export done.');
    }

    private function import(): void
    {
        // clear the tables, reset the IDs
        $this->clean();

        $manifest     = $this->ioService->readFile('forms/manifest.json');
        $fileNames    = json_decode($manifest);
        $formsCounter = $fieldsCounter = $submissionsCounter = 0;
        foreach ($fileNames as $fileName) {
            $form       = json_decode($this->ioService->readFile('forms/'.$fileName.'.json'));
            $formEntity = new Form();
            $formEntity->setName($form->name);
            $formEntity->setMaxSubmissions($form->maxSubmissions);
            $formEntity->setSendData(false);

            foreach ($form->fields as $field) {
                $className = '\\Novactive\\Bundle\\FormBuilderBundle\\Entity\\Field\\'.$field->type;
                /* @var Field $fieldEntity */
                $fieldEntity = new $className();
                $name        = \strlen($field->name) > 255 ? substr($field->name, 0, 255) : $field->name;
                $fieldEntity->setName($name);
                $fieldEntity->setWeight($field->weight);
                $fieldEntity->setRequired($field->required);
                $fieldEntity->setOptions((array) $field->options);
                $formEntity->addField($fieldEntity);
                ++$fieldsCounter;
            }

            // Importing Submissions
            if ($this->ioService->fileExists('forms/'.$fileName.'_submissions.json')) {
                $submissions = json_decode($this->ioService->readFile('forms/'.$fileName.'_submissions.json'));
                foreach ($submissions as $submission) {
                    $submissionEntity = new FormSubmission();
                    $submissionEntity->setCreatedAt(new \DateTime($submission->created_at));
                    $submissionEntity->setData((array) $submission->data);
                    $formEntity->addSubmission($submissionEntity);
                    ++$submissionsCounter;
                }
            }

            $this->formService->save($formEntity);
            ++$formsCounter;

            $this->io->writeln(
                "Imported #{$formEntity->getName()} with ".(string) $formEntity->getFields()->count().' fields and '.
                (string) $formEntity->getSubmissions()->count().' submissions.'
            );
        }

        $this->io->section(
            'Total: '.$formsCounter.' forms, '.$fieldsCounter.' fields, '.$submissionsCounter.' submissions.'
        );

        $this->io->success('Import done.');
    }

    private function clean(): void
    {
        $this->entityManager->getConnection()->query('DELETE FROM novaformbuilder_form');
        $this->entityManager->getConnection()->query('ALTER TABLE novaformbuilder_form AUTO_INCREMENT = 1');
        $this->entityManager->getConnection()->query('ALTER TABLE novaformbuilder_field AUTO_INCREMENT = 1');
        $this->entityManager->getConnection()->query('ALTER TABLE novaformbuilder_form_submission AUTO_INCREMENT = 1');
        $this->io->success('Current forms cleaned.');
    }

    private function runQuery(string $sql, array $parameters = [], $fetchMode = null): array
    {
        $stmt = $this->entityManager->getConnection()->prepare($sql);
        for ($i = 1, $iMax = count($parameters); $i <= $iMax; ++$i) {
            $stmt->bindValue($i, $parameters[$i - 1]);
        }
        $stmt->execute();

        return $stmt->fetchAll($fetchMode);
    }

    private function convertType(string $oldType): string
    {
        switch ($oldType) {
            case 'EmailEntry':
                $type = 'Email';
                break;
            case 'TextEntry':
                $type = 'TextArea';
                break;
            case 'NumberEntry':
                $type = 'Number';
                break;
            case 'MultipleChoice':
                $type = 'Choice';
                break;
            default:
                $type = $oldType;
        }

        return $type;
    }
}
