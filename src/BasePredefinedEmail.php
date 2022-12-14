<?php declare(strict_types=1);

namespace predefinedemailsforatk;

use Atk4\Data\Model;
use Atk4\Ui\Form\Control\Dropdown;
use Atk4\Ui\HtmlTemplate;
use traitsforatkdata\UserException;

abstract class BasePredefinedEmail extends Model
{

    public $table = 'predefined_email';

    //A title: what is this email for
    public string $title = '';
    //like title, but more descriptive: What is this email for
    public string $description = '';

    protected string $modelClass = '';

    //the template to load to get initial subject and message
    public string $defaultTemplateFile = '';
    public string $htmlTemplateClass = HtmlTemplate::class;
    protected HtmlTemplate $messageTemplate;
    protected HtmlTemplate $subjectTemplate;
    public bool $addHeaderAndFooter = true;

    //can it have multiple email templates, e.g. per Activity?
    protected bool $canHaveMultipleTemplates = false;

    //PHPMailer instance which takes care of the actual sending
    public string $phpMailerClass = PHPMailer::class;
    public PHPMailer $phpMailer;

    protected string $emailTemplateHandlerClass = BaseEmailTemplateHandler::class;
    protected BaseEmailTemplateHandler $emailTemplateHandler;

    protected function init(): void
    {
        parent::init();
        $this->addField(
            'subject_template'
        );
        $this->addField(
            'message_template',
            ['type' => 'text']
        );

        $this->hasOne(
            'email_account_id',
            [
                'model' => [EmailAccount::class],
                'type' => 'integer',
                'ui' => ['form' => [Dropdown::class, 'empty' => '...']]
            ]
        );

        $this->containsMany(EmailRecipient::class, ['model' => [EmailRecipient::class]]);
        $this->containsMany(Attachment::class, ['model' => [Attachment::class]]);

        $this->emailTemplateHandler = new $this->emailTemplateHandlerClass($this);
    }


    public function loadInitialValues()
    {
        $this->setModel();
        $this->loadInitialRecipients();
        $this->loadInitialAttachments();
        $this->loadInitialTemplate();
        $this->save();
    }

    protected function loadInitialTemplate(): void
    {
        $this->messageTemplate = $this->emailTemplateHandler->loadEmailTemplateForPredefinedEmail();
        $this->messageTemplate->trySet('recipient_firstname', '{$recipient_firstname}');
        $this->messageTemplate->trySet('recipient_lastname', '{$recipient_lastname}');
        $this->messageTemplate->trySet('recipient_email', '{$recipient_email}');
        //do this before extracting subject template to save duplicate code in implementations -
        // values to tags usually need to be set then only once
        $this->processMessageTemplateOnLoad();

        //get subject from Template if available
        if ($this->messageTemplate->hasTag('Subject')) {
            $this->subjectTemplate = $this->messageTemplate->cloneRegion('Subject');
            $this->messageTemplate->del('Subject');
        } else {
            $this->subjectTemplate = new HtmlTemplate('');
        }
        $this->processSubjectTemplateOnLoad();
        $this->set('subject_template', $this->subjectTemplate->renderToHtml());
        $this->set('message_template', $this->messageTemplate->renderToHtml());
    }


    public function addRecipient(string $emailAddress, string $firstname = '', string $lastname = ''): EmailRecipient
    {
        $emailAddress = trim($emailAddress);
        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            throw new UserException('The email address ' . $emailAddress . ' is not a valid.');
        }
        $emailRecipient = $this->ref(EmailRecipient::class);
        //some bug in ContainsMany. Switch this in-performant code to tryLoadBy() instead of iterating  with atk 3.x
        foreach ($emailRecipient as $er) {
            if ($er->get('email_address') === $emailAddress) {
                return $er;
            }
        }

        $emailRecipient->set('email_address', $emailAddress);
        $emailRecipient->set('firstname', $firstname);
        $emailRecipient->set('lastname', $lastname);
        $emailRecipient->save();

        return $emailRecipient;
    }

    public function removeRecipient($id): bool
    {
        foreach ($this->ref(EmailRecipient::class) as $emailRecipient) {
            if ($emailRecipient->getId() == $id) {
                $emailRecipient->delete();
                return true;
            }
        }

        return false;
    }

    public function addAttachment(string $filePath): Attachment
    {
        $attachment = $this->ref(Attachment::class);
        //some bug in ContainsMany. Switch this in-performant code to tryLoadBy() instead of iterating all  atk 3.x
        foreach ($attachment as $a) {
            if ($a->get('file_path') === $filePath) {
                return $a;
            }
        }
        $attachment->set('file_path', $filePath);
        $attachment->save();

        return $attachment;
    }

    public function removeAttachment($id): bool
    {
        foreach ($this->ref(Attachment::class) as $emailRecipient) {
            if ($emailRecipient->getId() == $id) {
                $emailRecipient->delete();
                return true;
            }
        }

        return false;
    }

    protected function getMessageTemplateForRecipient(EmailRecipient $emailRecipient): HtmlTemplate
    {
        //clone so changes per recipient won't affect other recipients
        $messageTemplate = clone $this->messageTemplate;

        //try to set the EmailRecipient fields in template
        $messageTemplate->trySet('recipient_firstname', $emailRecipient->get('firstname'));
        $messageTemplate->trySet('recipient_lastname', $emailRecipient->get('lastname'));
        $messageTemplate->trySet('recipient_email', $emailRecipient->get('email_address'));

        $this->processMessageTemplatePerRecipient($messageTemplate, $emailRecipient);

        return $messageTemplate;
    }

    protected function getSubjectTemplateForRecipient(EmailRecipient $emailRecipient): HtmlTemplate
    {
        //clone so changes per recipient won't affect other recipients
        $subjectTemplate = clone $this->subjectTemplate;

        //try to set the EmailRecipient fields in template
        $subjectTemplate->trySet('recipient_firstname', $emailRecipient->get('firstname'));
        $subjectTemplate->trySet('recipient_lastname', $emailRecipient->get('lastname'));
        $subjectTemplate->trySet('recipient_email', $emailRecipient->get('email_address'));

        $this->processSubjectTemplatePerRecipient($subjectTemplate, $emailRecipient);

        return $subjectTemplate;
    }

    /**
     * sends the message to each recipient
     * @return bool true if at least one send was successful, false otherwise
     */
    public function send(): bool
    {
        $this->prepareSend();

        $successfulSendingToAtLeastOneRecipient = false;
        //single send for each recipient
        foreach ($this->ref(EmailRecipient::class) as $recipient) {
            $this->phpMailer->Subject = $this->getSubjectTemplateForRecipient($recipient)->renderToHtml();
            $this->phpMailer->Body = $this->getMessageTemplateForRecipient($recipient)->renderToHtml();
            $this->phpMailer->AltBody = $this->phpMailer->html2text($this->phpMailer->Body);
            $this->phpMailer->addAddress(
                $recipient->get('email_address'),
                $recipient->get('firstname') . ' ' . $recipient->get('lastname')
            );

            //Send Email
            if ($this->phpMailer->send()) {
                $successfulSendingToAtLeastOneRecipient = true;
                //add Email to IMAP Sent Folder TODO move to PhpMailer
                $this->phpMailer->addSentEmailByIMAP();
            }
            //clear recipient after each Email
            $this->phpMailer->clearAddresses();
        }

        if ($successfulSendingToAtLeastOneRecipient) {
            $this->onSuccessfulSend();
        }

        //Email is sent, no need to store it any longer
        $this->delete();

        return $successfulSendingToAtLeastOneRecipient;
    }

    protected function prepareSend(): void
    {
        //superimportant, due to awful behaviour of ref() function we need to make sure $this is loaded
        if (!$this->loaded()) {
            $this->save();
        }
        $this->phpMailer = new $this->phpMailerClass($this->persistence);
        $this->phpMailer->setEmailAccount($this->get('email_account_id') ?? $this->getDefaultEmailAccountId());

        //add Attachments
        foreach ($this->ref(Attachment::class) as $attachment) {
            $this->phpMailer->addAttachment($attachment->get('file_path'));
        }

        //if email is sent to several recipients, keep SMTP connection open
        if ((int)$this->ref(EmailRecipient::class)->action('count')->getOne() > 1) {
            $this->phpMailer->SMTPKeepAlive = true;
        }

        $this->loadSubjectAndMessageTemplate();
    }

    protected function loadSubjectAndMessageTemplate(): void
    {
        $messageTemplate = $this->get('message_template');
        if ($this->addHeaderAndFooter) {
            $messageTemplate = $this->emailTemplateHandler->getHeaderTemplateString()
                . $messageTemplate . $this->emailTemplateHandler->getFooterTemplateString();
        }
        $this->messageTemplate = new $this->htmlTemplateClass($messageTemplate);
        $this->subjectTemplate = new $this->htmlTemplateClass($this->get('subject_template'));
    }

    /**
     * can be implemented in descendants. Can be used to set a standard Email Account to send from
     * when more than one is available
     */
    public function getDefaultEmailAccountId()
    {
        $ea = new EmailAccount($this->persistence);
        $ea->loadAny();
        return $ea->getId();
    }

    /**
     * The following methods can be implemented in child classes to create a custom behaviour.
     * Check the test files for sample usages.
     */

    protected function setModel(): void
    {
    }

    public function getModel(): ?Model
    {
        return null;
    }

    protected function processSubjectTemplatePerRecipient(
        HtmlTemplate $subjectTemplate,
        EmailRecipient $recipient
    ): void {
    }

    protected function processMessageTemplatePerRecipient(
        HtmlTemplate $messageTemplate,
        EmailRecipient $recipient
    ): void {
    }

    protected function processMessageTemplateOnLoad(): void
    {
    }

    protected function processSubjectTemplateOnLoad(): void
    {
    }

    protected function onSuccessfulSend(): void
    {
    }

    protected function loadInitialRecipients(): void
    {
    }

    protected function loadInitialAttachments(): void
    {
    }
}
