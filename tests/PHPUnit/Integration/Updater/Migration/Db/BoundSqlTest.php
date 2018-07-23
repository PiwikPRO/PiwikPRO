<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Updater\Migration\Db;

use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Updater\Migration\Db\BoundSql;

/**
 * @group Core
 * @group Updater
 * @group Migration
 * @group BoundSql
 * @group BoundSqlTest
 */
class BoundSqlTest extends IntegrationTestCase
{
    private $testQuery = 'ALTER TABLE foobar ADD COLUMN barbaz VARCHAR(1)';

    public function test_toString_shouldAppendSemicolonIfNeeded()
    {
        $sql = $this->boundSql($this->testQuery, array());

        $this->assertSame($this->testQuery . ';', '' . $sql);
    }

    public function test_toString_shouldNotAppendSemicolonIfNotNeeded()
    {
        $sql = $this->boundSql($this->testQuery . ';');

        $this->assertSame($this->testQuery . ';', '' . $sql);
    }

    public function test_toString_shouldReplacePlaceHolders()
    {
        $sql = $this->boundSql('DELETE FROM table WHERE x=?, foobar = ?, xyz = ?', array(
            'my value 1', 5, 'test\' val\ue"'
        ));

        $this->assertSame("DELETE FROM table WHERE x='my value 1', foobar = 5, xyz = 'test\' val\\\\ue\\\"';", '' . $sql);
    }

    public function test_constructor_shouldConvertErrorCodeToArray_IfNeeded()
    {
        $sql = $this->boundSql($this->testQuery, array(), 1091);
        $this->assertSame(array(1091), $sql->getErrorCodesToIgnore());
    }

    public function test_constructor_shouldNotConvertErrorCodeToArray_IfNotNeeded()
    {
        $sql = $this->boundSql($this->testQuery, array(), array(1091, 1061));
        $this->assertSame(array(1091, 1061), $sql->getErrorCodesToIgnore());
    }

    private function boundSql($query, $bind = array(), $errorCode = array())
    {
        return new BoundSql($query, $bind, $errorCode);
    }


}
