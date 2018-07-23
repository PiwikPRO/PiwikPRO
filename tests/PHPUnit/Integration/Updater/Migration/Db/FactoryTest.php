<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Updater\Migration\Db;

use Piwik\Common;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Updater\Migration\Db\AddColumn;
use Piwik\Updater\Migration\Db\AddColumns;
use Piwik\Updater\Migration\Db\AddIndex;
use Piwik\Updater\Migration\Db\AddPrimaryKey;
use Piwik\Updater\Migration\Db\AddUniqueKey;
use Piwik\Updater\Migration\Db\BatchInsert;
use Piwik\Updater\Migration\Db\BoundSql;
use Piwik\Updater\Migration\Db\ChangeColumn;
use Piwik\Updater\Migration\Db\ChangeColumnType;
use Piwik\Updater\Migration\Db\ChangeColumnTypes;
use Piwik\Updater\Migration\Db\CreateTable;
use Piwik\Updater\Migration\Db\DropColumn;
use Piwik\Updater\Migration\Db\DropIndex;
use Piwik\Updater\Migration\Db\DropTable;
use Piwik\Updater\Migration\Db\Factory;
use Piwik\Updater\Migration\Db\Insert;
use Piwik\Updater\Migration\Db\Sql;

/**
 * @group Core
 * @group Updater
 * @group Migration
 */
class FactoryTest extends IntegrationTestCase
{
    /**
     * @var Factory
     */
    private $factory;

    private $testTable = 'tablename';
    private $testTablePrefixed = '';

    public function setUp()
    {
        parent::setUp();
        
        $this->testTablePrefixed = Common::prefixTable($this->testTable);
        $this->factory = new Factory();
    }

    public function test_sql_returnsSqlInstance()
    {
        $migration = $this->sql();

        $this->assertTrue($migration instanceof Sql);
    }

    public function test_sql_forwardsQueryAndErrorCode()
    {
        $migration = $this->sql();

        $this->assertSame('SELECT 1;', '' . $migration);
        $this->assertSame(array(5), $migration->getErrorCodesToIgnore());
    }

    public function test_boundSql_returnsSqlInstance()
    {
        $migration = $this->boundSql();

        $this->assertTrue($migration instanceof BoundSql);
    }

    public function test_boundSql_forwardsParameters()
    {
        $migration = $this->boundSql();

        $this->assertSame("SELECT 2 WHERE 'query';", '' . $migration);
        $this->assertSame(array(8), $migration->getErrorCodesToIgnore());
    }

    public function test_createTable_returnsCreateTableInstance()
    {
        $migration = $this->createTable();

        $this->assertTrue($migration instanceof CreateTable);
    }

    public function test_createTable_forwardsParameters()
    {
        $migration = $this->createTable();

        $table = $this->testTablePrefixed;
        $this->assertSame("CREATE TABLE `$table` (`column` INT(10) DEFAULT 0, `column2` VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8;", ''. $migration);
    }

    public function test_createTable_withPrimaryKey()
    {
        $migration = $this->createTable('column2');

        $table = $this->testTablePrefixed;
        $this->assertSame("CREATE TABLE `$table` (`column` INT(10) DEFAULT 0, `column2` VARCHAR(255), PRIMARY KEY ( `column2` )) ENGINE=InnoDB DEFAULT CHARSET=utf8;", ''. $migration);
    }

    public function test_dropTable_returnsDropTableInstance()
    {
        $migration = $this->factory->dropTable($this->testTable);

        $this->assertTrue($migration instanceof DropTable);
    }

    public function test_dropTable_forwardsParameters()
    {
        $migration = $this->factory->dropTable($this->testTable);

        $table = $this->testTablePrefixed;
        $this->assertSame("DROP TABLE IF EXISTS `$table`;", ''. $migration);
    }

    public function test_dropColumn_returnsDropColumnInstance()
    {
        $migration = $this->dropColumn();

        $this->assertTrue($migration instanceof DropColumn);
    }

    public function test_dropColumn_forwardsParameters()
    {
        $migration = $this->dropColumn();

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` DROP COLUMN `column1`;", '' . $migration);
    }

    public function test_addColumn_forwardsParameters_withLastColumn()
    {
        $migration = $this->addColumn('lastcolumn');

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD COLUMN `column` INT(10) DEFAULT 0 AFTER `lastcolumn`;", '' . $migration);
    }

    public function test_addColumn_returnsAddColumnInstance()
    {
        $migration = $this->addColumn(null);

        $this->assertTrue($migration instanceof AddColumn);
    }

    public function test_addColumn_forwardsParameters_noLastColumn()
    {
        $migration = $this->addColumn(null);

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD COLUMN `column` INT(10) DEFAULT 0;", '' . $migration);
    }

    public function test_addColumns_returnsAddColumnsInstance()
    {
        $migration = $this->addColumns(null);

        $this->assertTrue($migration instanceof AddColumns);
    }

    public function test_addColumns_forwardsParameters()
    {
        $migration = $this->addColumns('columnafter');

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD COLUMN `column1` INT(10) DEFAULT 0 AFTER `columnafter`, ADD COLUMN `column2` VARCHAR(10) DEFAULT \"\" AFTER `column1`;", '' . $migration);
    }

    public function test_addColumns_NoAfterColumn()
    {
        $migration = $this->addColumns(null);

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD COLUMN `column1` INT(10) DEFAULT 0, ADD COLUMN `column2` VARCHAR(10) DEFAULT \"\";", '' . $migration);
    }

    public function test_changeColumn_returnsChangeColumnInstance()
    {
        $migration = $this->changeColumn();

        $this->assertTrue($migration instanceof ChangeColumn);
    }

    public function test_changeColumn_forwardsParameters()
    {
        $migration = $this->changeColumn();

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` CHANGE `column_old` `column_new` INT(10) DEFAULT 0;", '' . $migration);
    }

    public function test_changeColumnType_returnsChangeColumnTypeInstance()
    {
        $migration = $this->changeColumnType();

        $this->assertTrue($migration instanceof ChangeColumnType);
    }

    public function test_changeColumnType_forwardsParameters()
    {
        $migration = $this->changeColumnType();

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` CHANGE `column` `column` INT(10) DEFAULT 0;", '' . $migration);
    }

    public function test_changeColumnTypes_returnsChangeColumnTypesInstance()
    {
        $migration = $this->changeColumnTypes();

        $this->assertTrue($migration instanceof ChangeColumnTypes);
    }

    public function test_changeColumnTypes_forwardsParameters()
    {
        $migration = $this->changeColumnTypes();

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` CHANGE `column1` `column1` INT(10) DEFAULT 0, CHANGE `column2` `column2` VARCHAR(10) DEFAULT \"\";", '' . $migration);
    }

    public function test_addIndex_returnsAddIndexInstance()
    {
        $migration = $this->addIndex();

        $this->assertTrue($migration instanceof AddIndex);
    }

    public function test_addIndex_forwardsParameters_generatesIndexNameAutomatically()
    {
        $migration = $this->addIndex();

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD INDEX index_column1_column3 (`column1`, `column3` (10));", '' . $migration);
    }

    public function test_addIndex_customIndexName()
    {
        $migration = $this->addIndex('myCustomIndex');

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD INDEX myCustomIndex (`column1`, `column3` (10));", '' . $migration);
    }

    public function test_addUniqueKey_returnsAddUniqueKeyInstance()
    {
        $migration = $this->addUniqueKey();

        $this->assertTrue($migration instanceof AddUniqueKey);
    }

    public function test_addUniqueKey_forwardsParameters_generatesIndexNameAutomatically()
    {
        $migration = $this->addUniqueKey();

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD UNIQUE KEY unique_column1_column3 (`column1`, `column3` (10));", '' . $migration);
    }

    public function test_addUniqueKey_customIndexName()
    {
        $migration = $this->addUniqueKey('myCustomIndex');

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD UNIQUE KEY myCustomIndex (`column1`, `column3` (10));", '' . $migration);
    }

    public function test_dropIndex_returnsAddIndexInstance()
    {
        $migration = $this->dropIndex();

        $this->assertTrue($migration instanceof DropIndex);
    }

    public function test_addIndex_forwardsParameters()
    {
        $migration = $this->dropIndex();

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` DROP INDEX `index_column1_column5`;", '' . $migration);
    }

    public function test_addPrimaryKey()
    {
        $migration = $this->addPrimaryKey();

        $this->assertTrue($migration instanceof AddPrimaryKey);
    }

    public function test_addPrimaryKey_forwardsParameters()
    {
        $migration = $this->addPrimaryKey();

        $table = $this->testTablePrefixed;
        $this->assertSame("ALTER TABLE `$table` ADD PRIMARY KEY(`column1`, `column2`);", '' . $migration);
    }

    public function test_insert_returnsInsertInstance()
    {
        $migration = $this->insert();

        $this->assertTrue($migration instanceof Insert);
    }

    public function test_insert_forwardsParameters()
    {
        $migration = $this->insert();

        $table = $this->testTablePrefixed;
        $this->assertSame("INSERT INTO `$table` (`column1`, `column3`) VALUES ('val1',5);", ''. $migration);
    }

    public function test_batchInsert_returnsBatchInsertInstance()
    {
        $migration = $this->batchInsert();
        $this->assertTrue($migration instanceof BatchInsert);
    }

    public function test_batchInsert_forwardsParameters()
    {
        $migration = $this->batchInsert();
        $this->assertSame('<batch insert>', '' . $migration);
        $this->assertSame($this->testTablePrefixed, $migration->getTable());
        $this->assertSame(array('col1'), $migration->getColumnNames());
        $this->assertSame(array(array('val1')), $migration->getValues());
        $this->assertSame('utf8', $migration->getCharset());
        $this->assertTrue($migration->doesThrowException());
    }

    private function sql()
    {
        return $this->factory->sql('SELECT 1;', 5);
    }

    private function boundSql()
    {
        return $this->factory->boundSql('SELECT 2 WHERE ?;', array('column' => 'query'), array(8));
    }

    private function createTable($primaryKey = array())
    {
        return $this->factory->createTable($this->testTable, array('column' => 'INT(10) DEFAULT 0', 'column2' => 'VARCHAR(255)'), $primaryKey);
    }

    private function addColumn($placeAfterColumn)
    {
        return $this->factory->addColumn($this->testTable, 'column', 'INT(10) DEFAULT 0', $placeAfterColumn);
    }

    private function dropColumn()
    {
        return $this->factory->dropColumn($this->testTable, 'column1');
    }

    private function addColumns($placeAfterColumn)
    {
        return $this->factory->addColumns($this->testTable, array(
            'column1' => 'INT(10) DEFAULT 0',
            'column2' => 'VARCHAR(10) DEFAULT ""',
        ), $placeAfterColumn);
    }

    private function changeColumn()
    {
        return $this->factory->changeColumn($this->testTable, 'column_old', 'column_new', 'INT(10) DEFAULT 0');
    }

    private function changeColumnType()
    {
        return $this->factory->changeColumnType($this->testTable, 'column', 'INT(10) DEFAULT 0');
    }

    private function changeColumnTypes()
    {
        return $this->factory->changeColumnTypes($this->testTable, array(
            'column1' => 'INT(10) DEFAULT 0',
            'column2' => 'VARCHAR(10) DEFAULT ""',
        ));
    }

    private function addIndex($customIndex = '')
    {
        return $this->factory->addIndex($this->testTable, array('column1', 'column3 (10)'), $customIndex);
    }

    private function addUniqueKey($customIndex = '')
    {
        return $this->factory->addUniqueKey($this->testTable, array('column1', 'column3 (10)'), $customIndex);
    }

    private function dropIndex()
    {
        return $this->factory->dropIndex($this->testTable, 'index_column1_column5');
    }

    private function addPrimaryKey()
    {
        return $this->factory->addPrimaryKey($this->testTable, array('column1', 'column2'));
    }

    private function insert()
    {
        return $this->factory->insert($this->testTable, array('column1' => 'val1', 'column3' => 5));
    }

    private function batchInsert()
    {
        $columns = array('col1');
        $values = array(array('val1'));
        return $this->factory->batchInsert($this->testTable, $columns, $values, $throwException = true, $charset = 'utf8');
    }


}
