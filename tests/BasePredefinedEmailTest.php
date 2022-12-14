<?php declare(strict_types=1);

namespace predefinedemailsforatk\tests;

use predefinedemailsforatk\Attachment;
use predefinedemailsforatk\BasePredefinedEmail;
use predefinedemailsforatk\EmailAccount;
use predefinedemailsforatk\EmailRecipient;
use predefinedemailsforatk\EmailTemplate;
use predefinedemailsforatk\SentEmail;
use predefinedemailsforatk\tests\emailimplementations\EventSummaryForLocation;
use predefinedemailsforatk\tests\testclasses\Event;
use predefinedemailsforatk\tests\testclasses\FakePhpMailer;
use predefinedemailsforatk\tests\testclasses\Location;
use traitsforatkdata\TestCase;
use traitsforatkdata\UserException;

class BasePredefinedEmailTest extends TestCase
{
    private $persistence;

    protected $sqlitePersistenceModels = [
        Event::class,
        Location::class,
        EmailAccount::class,
        EmailTemplate::class,
        EventSummaryForLocation::class,
        SentEmail::class
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->persistence = $this->getSqliteTestPersistence();
    }

    public function testAddRecipientOnlyAddSameEmailAddressOnce()
    {
        $email = new EventSummaryForLocation($this->persistence);
        $email->save();

        $email->addRecipient('somefake@email.de', 'Max', 'Mustermann');
        self::assertSame(
            1,
            (int)$email->ref(EmailRecipient::class)->action('count')->getOne()
        );
        $email->addRecipient('somefake@email.de', 'Marina', 'Musterfrau');
        self::assertSame(
            1,
            (int)$email->ref(EmailRecipient::class)->action('count')->getOne()
        );
    }

    public function testAddRecipientInvalidEmailFormatTrowsException()
    {
        $email = new EventSummaryForLocation($this->persistence);
        $email->save();

        self::expectException(UserException::class);
        $email->addRecipient('someinvalid@email', 'Max', 'Mustermann');
    }

    public function testAddAndRemoveRecipient(): void
    {
        $email = new EventSummaryForLocation($this->persistence);
        $email->save();

        $recipient1 = $email->addRecipient('somefake@email.de', 'Max', 'Mustermann');
        $recipient2 = $email->addRecipient('someotherfake@email.de', 'Marina', 'Musterfrau');
        self::assertSame(
            2,
            (int)$email->ref(EmailRecipient::class)->action('count')->getOne()
        );

        $email->removeRecipient($recipient1->getId());
        self::assertSame(
            1,
            (int)$email->ref(EmailRecipient::class)->action('count')->getOne()
        );

        $email->removeRecipient($recipient2->getId());
        self::assertSame(
            0,
            (int)$email->ref(EmailRecipient::class)->action('count')->getOne()
        );
    }

    public function testAddSameAttachmentOnlyOnce(): void
    {
        $email = new EventSummaryForLocation($this->persistence);
        $email->save();

        $email->addAttachment(__DIR__ . '/testtemplatefiles/event_invitation.html');
        $email->addAttachment(__DIR__ . '/testtemplatefiles/event_invitation.html');
        self::assertSame(
            1,
            (int)$email->ref(Attachment::class)->action('count')->getOne()
        );
    }

    public function testAddAndRemoveAttachment(): void
    {
        $email = new EventSummaryForLocation($this->persistence);
        $email->save();

        $attachment1 = $email->addAttachment(__DIR__ . '/testtemplatefiles/event_invitation.html');
        $attachment2 = $email->addAttachment(__DIR__ . '/testtemplatefiles/event_summary_for_location.html');
        self::assertSame(
            2,
            (int)$email->ref(Attachment::class)->action('count')->getOne()
        );

        $email->removeAttachment($attachment1->getId());
        self::assertSame(
            1,
            (int)$email->ref(Attachment::class)->action('count')->getOne()
        );

        $email->removeAttachment($attachment2->getId());
        self::assertSame(
            0,
            (int)$email->ref(Attachment::class)->action('count')->getOne()
        );
    }

    public function testAddHeaderAndFooter(): void
    {
        $emailAccount = new EmailAccount($this->persistence);
        $emailAccount->save();
        $location = new Location($this->persistence);
        $location->save();

        $eventSummaryForLocation = new EventSummaryForLocation($this->persistence, ['location' => $location]);
        $eventSummaryForLocation->loadInitialValues();
        $eventSummaryForLocation->addRecipient('sometest@sometest.com', 'Peter', 'Maier');
        $eventSummaryForLocation->send();
        self::assertStringContainsString('<div id="header">', $eventSummaryForLocation->phpMailer->Body);
        self::assertStringContainsString('<div id="footer">', $eventSummaryForLocation->phpMailer->Body);

        $eventSummaryForLocation = new EventSummaryForLocation(
            $this->persistence,
            [
                'location' => $location,
                'addHeaderAndFooter' => false
            ]
        );
        $eventSummaryForLocation->loadInitialValues();
        $eventSummaryForLocation->addRecipient('sometest@sometest.com', 'Peter', 'Maier');
        $eventSummaryForLocation->send();
        self::assertStringNotContainsString('<div id="header">', $eventSummaryForLocation->phpMailer->Body);
        self::assertStringNotContainsString('<div id="footer">', $eventSummaryForLocation->phpMailer->Body);
    }

    public function testProcessSubjectAndMessagePerRecipient()
    {
        $emailAccount = new EmailAccount($this->persistence);
        $emailAccount->save();
        $location = new Location($this->persistence);
        $location->save();
        $eventSummaryForLocation = new EventSummaryForLocation($this->persistence, ['location' => $location]);
        $eventSummaryForLocation->loadInitialValues();
        $eventSummaryForLocation->addRecipient('sometest1@sometest.com', 'Peter', 'Maier');
        $eventSummaryForLocation->send();
        self::assertStringNotContainsString('Hans', $eventSummaryForLocation->phpMailer->Body);
        self::assertStringNotContainsString('Hans', $eventSummaryForLocation->phpMailer->Subject);

        $eventSummaryForLocation = new EventSummaryForLocation($this->persistence, ['location' => $location]);
        $eventSummaryForLocation->loadInitialValues();
        $eventSummaryForLocation->addRecipient('sometest2@sometest.com', 'Hans', 'Maier');
        $eventSummaryForLocation->send();
        self::assertStringContainsString('Hans', $eventSummaryForLocation->phpMailer->Body);
        self::assertStringContainsString('Hans', $eventSummaryForLocation->phpMailer->Subject);
    }

    public function testOnSuccessfulSend()
    {
        $emailAccount = new EmailAccount($this->persistence);
        $emailAccount->save();
        $location = new Location($this->persistence);
        $location->save();
        $eventSummaryForLocation = new EventSummaryForLocation(
            $this->persistence,
            [
                'location' => $location,
                'phpMailerClass' => FakePhpMailer::class
            ]
        );
        $eventSummaryForLocation->loadInitialValues();
        $eventSummaryForLocation->addRecipient('sometest2@sometest.com');
        $eventSummaryForLocation->send();
        self::assertSame(
            1,
            (int)$location->ref(SentEmail::class)->action('count')->getOne()
        );
        self::assertSame(
            EventSummaryForLocation::class,
            $location->ref(SentEmail::class)->loadAny()->get('value')
        );
    }

    public function testSendFromOtherEmailAccount()
    {
        $location = new Location($this->persistence);
        $location->save();
        $emailAccount1 = new EmailAccount($this->persistence);
        $emailAccount1->set('email_address', 'sometest1@sometest.com');
        $emailAccount1->set('sender_name', 'TESTSENDER1');
        $emailAccount1->save();

        $emailAccount2 = new EmailAccount($this->persistence);
        $emailAccount2->set('email_address', 'sometest2@sometest.com');
        $emailAccount2->set('sender_name', 'TESTSENDER2');
        $emailAccount2->save();

        $eventSummaryForLocation = new EventSummaryForLocation(
            $this->persistence,
            [
                'location' => $location,
                'phpMailerClass' => FakePhpMailer::class
            ]
        );
        $eventSummaryForLocation->loadInitialValues();
        $eventSummaryForLocation2 = clone $eventSummaryForLocation;

        $eventSummaryForLocation->set('email_account_id', $emailAccount1->getId());
        $eventSummaryForLocation->send();
        self::assertSame('TESTSENDER1', $eventSummaryForLocation->phpMailer->FromName);

        $eventSummaryForLocation2->set('email_account_id', $emailAccount2->getId());
        $eventSummaryForLocation2->send();
        self::assertSame('TESTSENDER2', $eventSummaryForLocation2->phpMailer->FromName);
    }
}
