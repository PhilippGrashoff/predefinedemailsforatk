<?php declare(strict_types=1);

namespace predefinedemailsforatk\tests;

use predefinedemailsforatk\EmailAccount;
use traitsforatkdata\TestCase;

class EmailAccountTest extends TestCase
{

    protected $sqlitePersistenceModels = [
        EmailAccount::class,
    ];

    public function testJustGetCoverage()
    {
        $persistence = $this->getSqliteTestPersistence();
        $ea = new EmailAccount($persistence);
        self::assertInstanceOf(EmailAccount::class, $ea);
    }
}
