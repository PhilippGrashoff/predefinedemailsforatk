<?php declare(strict_types=1);

namespace emailboilerplateforatkdata;

use Atk4\Data\Persistence;
use Atk4\Ui\HtmlTemplate;
use DirectoryIterator;
use Throwable;


abstract class BaseEmailTemplateHandler
{

    protected BasePredefinedEmail $baseEmail;
    protected string $htmlTemplateClass = HtmlTemplate::class;

    public function __construct(BasePredefinedEmail $baseEmail = null)
    {
        if ($baseEmail) {
            $this->baseEmail = $baseEmail;
        }
    }

    public function getEmailTemplate(): HtmlTemplate
    {
        //try load Template for the individual $baseEmail->model entity
        $result = $this->tryLoadTemplateForEntity();
        if ($result) {
            return $result;
        }
        //try load existing Template from persistence
        $result = $this->tryLoadTemplateFromPersistence();
        if ($result) {
            return $result;
        }
        //load default template from file if other methods did not return anything
        return $this->loadDefaultTemplateFromFile();
    }

    protected function tryLoadTemplateForEntity(): ?HtmlTemplate
    {
        //no model or not loaded?
        if (!$this->baseEmail->entity || !$this->baseEmail->entity->loaded()) {
            return null;
        }

        return $this->customLoadTemplateForEntity();
    }

    //overwrite for custom implementations
    protected function customLoadTemplateForEntity(): ?HtmlTemplate
    {
        return null;
    }

    protected function tryLoadTemplateFromPersistence(): ?HtmlTemplate
    {
        $emailTemplate = new EmailTemplate($this->baseEmail->persistence);
        $emailTemplate->addCondition('model_class', '=', null);
        $emailTemplate->addCondition('model_id', '=', null);
        $emailTemplate->tryLoadBy('ident', (new \ReflectionClass($this->baseEmail))->getName());
        if (!$emailTemplate->loaded()) {
            return null;
        }
        $htmlTemplate = new $this->htmlTemplateClass($emailTemplate->get('value'));
        return $htmlTemplate;
    }

    protected function loadDefaultTemplateFromFile(): HtmlTemplate
    {
        //now try to load from file
        $fileName = $this->getTemplateFilePath();
        //throws Exception if file can not be found
        $htmlTemplate = new $this->htmlTemplateClass();
        $htmlTemplate->loadFromFile($fileName);
        return $htmlTemplate;
    }

    protected function loadRawDefaultTemplateFromFile(): string
    {
        //now try to load from file
        $fileName = $this->getTemplateFilePath();
        return file_get_contents($fileName);
    }

    //overwrite in custom implementations to easily define where default template files can be found
    protected function getTemplateFilePath(): string
    {
        return $this->baseEmail->defaultTemplateFile;
    }

    /**
     * helper function to add EmailTemplate Entities to persistence. The default template from a file is used.
     * Useful for creating a UI where users can alter the Email Templates and saving the changes to persistence.
     */
    public function createEmailTemplateEntities(string $directory, Persistence $persistence): void
    {
        foreach (self:: getAllBaseEmailImplementations($directory, $persistence) as $baseEmail) {
            $this->baseEmail = $baseEmail;
            $emailTemplate = new EmailTemplate($persistence);
            $emailTemplate->addCondition('model_class', null);
            $emailTemplate->addCondition('model_id', null);
            $emailTemplate->tryLoadBy('ident', (new \ReflectionClass($this->baseEmail))->getName());
            if (!$emailTemplate->loaded()) {
                $emailTemplate->set('ident', (new \ReflectionClass($this->baseEmail))->getName());
                $emailTemplate->set('value', $this->loadRawDefaultTemplateFromFile());
                $emailTemplate->save();
            }
        }
    }

    /**
     * return an instance of each found implementation of BasePredefinedEmail in the given folder(s)
     * parameter array: key is the dir to check for classes, value is the namespace
     */
    protected function getAllBaseEmailImplementations(string $directory, Persistence $persistence): array
    {
        $result = [];
        foreach ((new DirectoryIterator($directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $className = $this->getNameSpaceFromFile($file) . '\\' . $file->getBasename('.php');
            if (!class_exists($className)) {
                continue;
            }
            try {
                $instance = new $className($persistence);
            } catch (Throwable $e) {
                continue;
            }
            if (!$instance instanceof BasePredefinedEmail) {
                continue;
            }
            $result[$className] = clone $instance;
        }

        return $result;
    }

    protected function getNameSpaceFromFile(DirectoryIterator $file): string
    {
        $phpCode = file_get_contents($file->getPathname());
        $tokens = token_get_all($phpCode);
        $count = count($tokens);
        $i = 0;
        $namespace = '';
        while ($i < $count) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                // Found namespace declaration
                while (++$i < $count) {
                    if ($tokens[$i] === ';') {
                        break;
                    }
                    $namespace .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                }
                break;
            }
            $i++;
        }

        return trim($namespace);
    }
}