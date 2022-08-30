<?php

use Intersvyaz\Pdo\Oci8;
use Intersvyaz\Pdo\Oci8Exception;
use PHPUnit\Framework\TestCase;

/**
 * Тестирование функционала Oracle
 */
class Oci8StatementTest extends TestCase
{
    const DEFAULT_USER = '';
    const DEFAULT_PWD = '';
    const DEFAULT_DSN = '';

    /** @var Oci8|null  */
    protected ?Oci8 $con = null;

    /**
     * Настройки перед запуском тестов
     */
    public function setUp(): void
    {
        $user = getenv('OCI_USER') ?: self::DEFAULT_USER;
        $pwd = getenv('OCI_PWD') ?: self::DEFAULT_PWD;
        $dsn = getenv('OCI_DSN') ?: self::DEFAULT_DSN;
        $this->con = new Oci8($dsn, $user, $pwd, [PDO::ATTR_CASE => PDO::CASE_NATURAL]);
        $this->con->test = true;
    }

    /**
     * Тест проверки, что соединение есть
     */
    public function testObject()
    {
        $this->assertNotNull($this->con);
    }

    /**
     * Удачный постоянный коннект
     */
    public function testPersistentConnection()
    {
        $user = getenv('OCI_USER') ?: self::DEFAULT_USER;
        $pwd = getenv('OCI_PWD') ?: self::DEFAULT_PWD;
        $dsn = getenv('OCI_DSN') ?: self::DEFAULT_DSN;
        $con = new Oci8($dsn, $user, $pwd, [PDO::ATTR_PERSISTENT => true]);

        $this->assertNotNull($con);
    }

    /**
     * Удачный коннект с параметрами
     */
    public function testConnectionWithParameters()
    {
        $user = getenv('OCI_USER') ?: self::DEFAULT_USER;
        $pwd = getenv('OCI_PWD') ?: self::DEFAULT_PWD;
        $dsn = getenv('OCI_DSN') ?: self::DEFAULT_DSN;
        $con = new Oci8("$dsn;charset=utf8", $user, $pwd);
        $this->assertNotNull($con);
    }

    /**
     * Тест на exception при установлении соединения
     */
    public function testInvalidConnection()
    {
        $user = 'pdooci';
        $pwd = 'pdooci';
        $str = self::DEFAULT_DSN;
        $this->expectException(Oci8Exception::class);
        new Oci8($str, $user, $pwd, [PDO::ATTR_PERSISTENT => true]);
    }

    /**
     * Тест на установку и получение атрибута
     */
    public function testAttributes()
    {
        $this->con->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        $this->assertTrue($this->con->getAttribute(PDO::ATTR_AUTOCOMMIT));
    }

    /**
     * Тест на получение ошибки, что такой таблицы нет
     */
    public function testErrorCode()
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionCode(942);
        $this->con->exec("insert into asd (asd) values ('asd')");
    }

    /**
     * Тест на проверку, что драйвер OCI есть
     */
    public function testDrivers()
    {
        $this->assertTrue(in_array('oci', $this->con->getAvailableDrivers()));
    }

    /**
     * Тест проверки транзакций
     */
    public function testInTransaction()
    {
        $this->con->beginTransaction();
        $this->assertTrue($this->con->inTransaction());
        $this->con->commit();
        $this->assertFalse($this->con->inTransaction());
    }

    /**
     * Test quotes.
     */
    public function testQuote()
    {
        $this->assertEquals("'Nice'", $this->con->quote('Nice'));
        $this->assertEquals("'Not '' string'", $this->con->quote('Not \' string'));
    }

    /**
     * Test if returns the last inserted id with a sequence.
     */
    public function testLastIdWithSequence()
    {
        $id = $this->con->lastInsertId('A_PAYS_TRN_SEQ');
        $this->assertTrue(is_numeric($id));
    }

    /**
     *
     */
    public function testCaseDefaultValue()
    {
        $case = $this->con->getAttribute(PDO::ATTR_CASE);
        $this->assertEquals(PDO::CASE_NATURAL, $case);
    }

    /**
     * Test setting case.
     *
     * @dataProvider caseProvider
     */
    public function testSettingCase()
    {
        $case = 'FIELD';
        $this->con->setAttribute(PDO::ATTR_CASE, $case);
        $this->assertEquals($case, $this->con->getAttribute(PDO::ATTR_CASE));
    }

    public function caseProvider(): array
    {
        return [
            [PDO::CASE_LOWER],
            [PDO::CASE_UPPER],
        ];
    }

    /**
     * Тест создания запроса
     */
    public function testQuery()
    {
        $statement = $this->con->query('SELECT table_name FROM user_tables', null, null);
        $this->assertInstanceOf(PDOStatement::class, $statement);
    }

    /**
     * Тест установки одного параметра
     */
    public function testBindParamSingle()
    {
        $stmt = $this->con->prepare('INSERT INTO person (name) VALUES (:a)');
        $var = 'Joop';
        $this->assertTrue($stmt->bindParam('a', $var, PDO::PARAM_STR));
    }

    /**
     * Тест установки нескольких параметров
     */
    public function testBindParamMultiple()
    {
        $var = 'Joop';
        $email = 'joop@world.com';
        $phone = 9639995544;
        $stmt = $this->con->prepare('INSERT INTO person, email, phone (name) VALUES (:person, :email, :phone)');

        $this->assertTrue($stmt->bindParam(':person', $var, PDO::PARAM_STR));
        $this->assertTrue($stmt->bindParam(':email', $email, PDO::PARAM_STR));
        $this->assertTrue($stmt->bindParam(':phone', $phone, PDO::PARAM_INT));
    }


    /**
     * Тест установки нескольких значений
     */
    public function testBindValueMultiple()
    {
        $var = 'Joop';
        $email = 'joop@world.com';
        $phone = 9639995544;
        $stmt = $this->con->prepare('INSERT INTO person, email, phone (name) VALUES (:person, :email, :phone)');

        $this->assertTrue($stmt->bindValue(':person', $var, PDO::PARAM_STR));
        $this->assertTrue($stmt->bindValue(':email', $email, PDO::PARAM_STR));
        $this->assertTrue($stmt->bindParam(':phone', $phone, PDO::PARAM_INT));
    }

    public function testSetConnectionIdentifier()
    {
        $expectedIdentifier = 'PDO_OCI8_CON';

        $user = getenv('OCI_USER') ?: self::DEFAULT_USER;
        $pwd = getenv('OCI_PWD') ?: self::DEFAULT_PWD;
        $dsn = getenv('OCI_DSN') ?: self::DEFAULT_DSN;
        $con = new Oci8($dsn, $user, $pwd);
        $this->assertNotNull($con);

        $con->setClientIdentifier($expectedIdentifier);
        $stmt = $con->query("SELECT SYS_CONTEXT('USERENV','CLIENT_IDENTIFIER') as IDENTIFIER FROM DUAL");
        $foundClientIdentifier = $stmt->fetchColumn(0);
        $con->close();

        $this->assertEquals($expectedIdentifier, $foundClientIdentifier);
    }
}