<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration;

use Exception;
use Piwik\ArchiveProcessor\Rules;
use Piwik\Cache;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Db;
use Piwik\Segment;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\Action;
use Piwik\Tracker\TableLogAction;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;

/**
 * @group Core
 * @group Segment
 */
class SegmentTest extends IntegrationTestCase
{
    public $tableLogActionCacheHits = 0;

    private $exampleSegment = 'visitCount>=1';

    public function setUp()
    {
        parent::setUp();

        // setup the access layer (required in Segment contrustor testing if anonymous is allowed to use segments)
        FakeAccess::$superUser = true;

        Fixture::createWebsite('2015-01-01 00:00:00');
    }

    static public function removeExtraWhiteSpaces($valueToFilter)
    {
        if (is_array($valueToFilter)) {
            foreach ($valueToFilter as $key => $value) {
                $valueToFilter[$key] = self::removeExtraWhiteSpaces($value);
            }
            return $valueToFilter;
        } else {
            return preg_replace('/[\s]+/', ' ', $valueToFilter);
        }
    }

    public function getCommonTestData()
    {
        return array(
            // Normal segment
            array('countryCode==France', array(
                'where' => ' log_visit.location_country = ? ',
                'bind'  => array('France'))),

            // unescape the comma please
            array('countryCode==a\,==', array(
                'where' => ' log_visit.location_country = ? ',
                'bind'  => array('a,=='))),

            // AND, with 2 values rewrites
            array('countryCode==a;visitorType!=returning;visitorType==new', array(
                'where' => ' log_visit.location_country = ? AND ( log_visit.visitor_returning IS NULL OR log_visit.visitor_returning <> ? ) AND log_visit.visitor_returning = ? ',
                'bind'  => array('a', '1', '0'))),

            // OR, with 2 value rewrites
            array('referrerType==search,referrerType==direct', array(
                'where' => ' (log_visit.referer_type = ? OR log_visit.referer_type = ? )',
                'bind'  => array(Common::REFERRER_TYPE_SEARCH_ENGINE,
                                 Common::REFERRER_TYPE_DIRECT_ENTRY))),

            // IS NOT NULL
            array('browserCode==ff;referrerKeyword!=', array(
                'where' => ' log_visit.config_browser_name = ? AND ( log_visit.referer_keyword IS NOT NULL AND (log_visit.referer_keyword <> \'\' OR log_visit.referer_keyword = 0) ) ',
                'bind'  => array('ff')
            )),
            array('referrerKeyword!=,browserCode==ff', array(
                'where' => ' (( log_visit.referer_keyword IS NOT NULL AND (log_visit.referer_keyword <> \'\' OR log_visit.referer_keyword = 0) ) OR log_visit.config_browser_name = ? )',
                'bind'  => array('ff')
            )),

            // IS NULL
            array('browserCode==ff;referrerKeyword==', array(
                'where' => ' log_visit.config_browser_name = ? AND ( log_visit.referer_keyword IS NULL OR log_visit.referer_keyword = \'\' ) ',
                'bind'  => array('ff')
            )),
            array('referrerKeyword==,browserCode==ff', array(
                'where' => ' (( log_visit.referer_keyword IS NULL OR log_visit.referer_keyword = \'\' ) OR log_visit.config_browser_name = ? )',
                'bind'  => array('ff')
            )),

            // test multiple column segments
            array('customVariableName==abc;customVariableValue==def', array(
                'where' => ' (log_visit.custom_var_k1 = ? OR log_visit.custom_var_k2 = ? OR log_visit.custom_var_k3 = ? OR log_visit.custom_var_k4 = ? OR log_visit.custom_var_k5 = ?) AND (log_visit.custom_var_v1 = ? OR log_visit.custom_var_v2 = ? OR log_visit.custom_var_v3 = ? OR log_visit.custom_var_v4 = ? OR log_visit.custom_var_v5 = ? )',
                'bind' => array(
                    'abc', 'abc', 'abc', 'abc', 'abc',
                    'def', 'def', 'def', 'def', 'def',
                ),
            )),
        );
    }

    /**
     * @dataProvider getCommonTestData
     */
    public function testCommon($segment, $expected)
    {
        $select = 'log_visit.idvisit';
        $from = 'log_visit';

        $expected = array(
            'sql'  => '
                SELECT
                    log_visit.idvisit
                FROM
                    ' . Common::prefixTable('log_visit') . ' AS log_visit
                WHERE
                    ' . $expected['where'],
            'bind' => $expected['bind']
        );

        $segment = new Segment($segment, $idSites = array());
        $sql = $segment->getSelectQuery($select, $from, false);
        $this->assertQueryDoesNotFail($sql);

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($sql));

        // calling twice should give same results
        $sql = $segment->getSelectQuery($select, array($from));
        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($sql));

        $this->assertEquals(32, strlen($segment->getHash()));
    }

    public function test_getSelectQuery_whenNoJoin()
    {
        $select = '*';
        $from = 'log_visit';
        $where = 'idsite = ?';
        $bind = array(1);

        $segment = 'customVariableName1==Test;visitorType==new';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                WHERE
                    ( idsite = ? )
                    AND
                    ( log_visit.custom_var_k1 = ? AND log_visit.visitor_returning = ? )",
            "bind" => array(1, 'Test', 0));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinVisitOnLogLinkVisitAction()
    {
        $select = '*';
        $from = 'log_link_visit_action';
        $where = 'log_link_visit_action.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test;visitorType==new';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action
                    LEFT JOIN " . Common::prefixTable('log_visit') . " AS log_visit ON log_visit.idvisit = log_link_visit_action.idvisit
                WHERE
                    ( log_link_visit_action.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? AND log_visit.visitor_returning = ? )",
            "bind" => array(1, 'Test', 0));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinActionOnVisit()
    {
        $select = 'sum(log_visit.visit_total_actions) as nb_actions, max(log_visit.visit_total_actions) as max_actions, sum(log_visit.visit_total_time) as sum_visit_length';
        $from = 'log_visit';
        $where = 'log_visit.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test;visitorType==new';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    sum(log_inner.visit_total_actions) as nb_actions, max(log_inner.visit_total_actions) as max_actions, sum(log_inner.visit_total_time) as sum_visit_length
                FROM
                    (
                SELECT
                    log_visit.visit_total_actions,
                    log_visit.visit_total_time
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE
                    ( log_visit.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? AND log_visit.visitor_returning = ? )
                GROUP BY log_visit.idvisit
                ORDER BY NULL
                    ) AS log_inner",
            "bind" => array(1, 'Test', 0));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinConversionOnLogLinkVisitAction()
    {
        $select = '*';
        $from = 'log_link_visit_action';
        $where = 'log_link_visit_action.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test;visitConvertedGoalId==1;customVariablePageName2==Test2';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action
                    LEFT JOIN " . Common::prefixTable('log_conversion') . " AS log_conversion ON log_conversion.idvisit = log_link_visit_action.idvisit
                WHERE
                    ( log_link_visit_action.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? AND log_conversion.idgoal = ? AND log_link_visit_action.custom_var_k2 = ? )",
            "bind" => array(1, 'Test', 1, 'Test2'));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinActionOnConversion()
    {
        $select = '*';
        $from = 'log_conversion';
        $where = 'log_conversion.idvisit = ?';
        $bind = array(1);

        $segment = 'visitConvertedGoalId!=2;customVariablePageName1==Test;visitConvertedGoalId==1';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Common::prefixTable('log_conversion') . " AS log_conversion
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_conversion.idvisit
                WHERE
                    ( log_conversion.idvisit = ? )
                    AND
                    ( ( log_conversion.idgoal IS NULL OR log_conversion.idgoal <> ? ) AND log_link_visit_action.custom_var_k1 = ? AND log_conversion.idgoal = ? )",
            "bind" => array(1, 2, 'Test', 1));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinConversionOnVisit()
    {
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = 'log_visit.idvisit = ?';
        $bind = array(1);

        $segment = 'visitConvertedGoalId==1';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    log_inner.*
                FROM
                    (
                SELECT
                    log_visit.*
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_conversion') . " AS log_conversion ON log_conversion.idvisit = log_visit.idvisit
                WHERE
                    ( log_visit.idvisit = ? )
                    AND
                    ( log_conversion.idgoal = ? )
                GROUP BY log_visit.idvisit
                ORDER BY NULL
                    ) AS log_inner",
            "bind" => array(1, 1));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinConversionOnly()
    {
        $select = 'log_conversion.*';
        $from = 'log_conversion';
        $where = 'log_conversion.idvisit = ?';
        $bind = array(1);

        $segment = 'visitConvertedGoalId==1';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    log_conversion.*
                FROM
                    " . Common::prefixTable('log_conversion') . " AS log_conversion
                WHERE
                    ( log_conversion.idvisit = ? )
                    AND
                    ( log_conversion.idgoal = ? )",
            "bind" => array(1, 1));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinVisitOnConversion()
    {
        $select = '*';
        $from = 'log_conversion';
        $where = 'log_conversion.idvisit = ?';
        $bind = array(1);

        $segment = 'visitConvertedGoalId==1,visitServerHour==12';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Common::prefixTable('log_conversion') . " AS log_conversion
                    LEFT JOIN " . Common::prefixTable('log_visit') . " AS log_visit ON log_visit.idvisit = log_conversion.idvisit
                WHERE
                    ( log_conversion.idvisit = ? )
                    AND
                    ( (log_conversion.idgoal = ? OR HOUR(log_visit.visit_last_action_time) = ? ))",
            "bind" => array(1, 1, 12));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinLogLinkVisitActionOnActionOnVisit_WithSameTableAlias()
    {
        $actionType = 3;
        $idSite = 1;
        $select = 'log_link_visit_action.custom_dimension_1,
                  log_action.name as url,
                  sum(log_link_visit_action.time_spent) as `13`,
                  sum(case log_visit.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`';
        $from  = array(
            'log_link_visit_action',
            array('table' => 'log_visit', 'joinOn' => 'log_visit.idvisit = log_link_visit_action.idvisit'),
            array('table' => 'log_action', 'joinOn' => 'log_link_visit_action.idaction_url = log_action.idaction'),
            'log_visit'
        );
        $where = 'log_link_visit_action.server_time >= ?
                  AND log_link_visit_action.server_time <= ?
                  AND log_link_visit_action.idsite = ?';
        $bind = array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite);

        $segment = 'actionType==' . $actionType;
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $logVisitTable = Common::prefixTable('log_visit');
        $logActionTable = Common::prefixTable('log_action');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');

        $expected = array(
            "sql"  => "
             SELECT log_link_visit_action.custom_dimension_1,
                    log_action.name as url,
                    sum(log_link_visit_action.time_spent) as `13`,
                    sum(case log_visit.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`
             FROM $logLinkVisitActionTable AS log_link_visit_action
                  LEFT JOIN $logVisitTable AS log_visit
                       ON log_visit.idvisit = log_link_visit_action.idvisit
                  LEFT JOIN $logActionTable AS log_action
                       ON log_link_visit_action.idaction_url = log_action.idaction
             WHERE ( log_link_visit_action.server_time >= ?
                 AND log_link_visit_action.server_time <= ?
                 AND log_link_visit_action.idsite = ? )
                 AND ( log_action.type = ? )",
            "bind" => array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite, $actionType));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoiningManyCustomTablesItShouldKeepTheOrderAsDefined()
    {
        $actionType = 3;
        $idSite = 1;
        $select = 'log_link_visit_action.custom_dimension_1,
                  log_action.name as url,
                  sum(log_link_visit_action.time_spent) as `13`,
                  sum(case log_visit.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`';
        $from  = array(
            'log_link_visit_action',
            'log_visit',
            array(
                'table' => 'log_link_visit_action',
                'tableAlias' => 'log_link_visit_action_foo',
                'joinOn' => 'log_link_visit_action.idvisit = log_link_visit_action_foo.idvisit',
            ),
            array(
                'table' => 'log_action',
                'tableAlias' => 'log_action_foo',
                'joinOn' => 'log_link_visit_action_foo.idaction_url = log_action_foo.idaction',
            ),
            array(
                'table' => 'log_link_visit_action',
                'tableAlias' => 'log_link_visit_action_bar',
                'joinOn' => "log_link_visit_action.idvisit = log_link_visit_action_bar.idvisit"
            ),
            array(
                'table' => 'log_action',
                'tableAlias' => 'log_action_bar',
                'joinOn' => "log_link_visit_action_bar.idaction_url = log_action_bar.idaction"
            ),
            array(
                'table' => 'log_link_visit_action',
                'tableAlias' => 'log_link_visit_action_baz',
                'joinOn' => "log_link_visit_action.idvisit = log_link_visit_action_baz.idvisit"
            ),
            array(
                'table' => 'log_action',
                'tableAlias' => 'log_action_baz',
                'joinOn' => "log_link_visit_action_baz.idaction_url = log_action_baz.idaction"
            ),
            'log_action',
        );

        $where = 'log_link_visit_action.server_time >= ?
                  AND log_link_visit_action.server_time <= ?
                  AND log_link_visit_action.idsite = ?';
        $bind = array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite);

        $segment = 'actionType==' . $actionType;
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $logActionTable = Common::prefixTable('log_action');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');
        $logVisitTable = Common::prefixTable('log_visit');

        $expected = array(
            "sql"  => "
             SELECT log_link_visit_action.custom_dimension_1,
                    log_action.name as url,
                    sum(log_link_visit_action.time_spent) as `13`,
                    sum(case log_visit.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`
             FROM $logLinkVisitActionTable AS log_link_visit_action 
             LEFT JOIN $logActionTable AS log_action ON log_link_visit_action.idaction_url = log_action.idaction 
             LEFT JOIN $logVisitTable AS log_visit ON log_visit.idvisit = log_link_visit_action.idvisit 
             LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action_foo ON log_link_visit_action.idvisit = log_link_visit_action_foo.idvisit 
             LEFT JOIN $logActionTable AS log_action_foo ON log_link_visit_action_foo.idaction_url = log_action_foo.idaction 
             LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action_bar ON log_link_visit_action.idvisit = log_link_visit_action_bar.idvisit 
             LEFT JOIN $logActionTable AS log_action_bar ON log_link_visit_action_bar.idaction_url = log_action_bar.idaction 
             LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action_baz ON log_link_visit_action.idvisit = log_link_visit_action_baz.idvisit 
             LEFT JOIN $logActionTable AS log_action_baz ON log_link_visit_action_baz.idaction_url = log_action_baz.idaction
             WHERE ( log_link_visit_action.server_time >= ?
                 AND log_link_visit_action.server_time <= ?
                 AND log_link_visit_action.idsite = ? )
                 AND ( log_action.type = ? )",
            "bind" => array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite, $actionType));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinLogLinkVisitActionOnActionOnVisit_WithNoTableAliasButDifferentJoin()
    {
        $actionType = 3;
        $idSite = 1;
        $select = 'log_link_visit_action.custom_dimension_1,
                  log_action.name as url,
                  sum(log_link_visit_action.time_spent) as `13`,
                  sum(case log_visit.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`';
        $from  = array(
            'log_link_visit_action',
            array('table' => 'log_visit', 'joinOn' => 'log_visit.idvisit = log_link_visit_action.idvisit'),
            array('table' => 'log_action', 'joinOn' => 'log_link_visit_action.idaction_name = log_action.idaction')
        );
        $where = 'log_link_visit_action.server_time >= ?
                  AND log_link_visit_action.server_time <= ?
                  AND log_link_visit_action.idsite = ?';
        $bind = array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite);

        $segment = 'actionType==' . $actionType;
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $logVisitTable = Common::prefixTable('log_visit');
        $logActionTable = Common::prefixTable('log_action');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');

        $expected = array(
            "sql"  => "
             SELECT log_link_visit_action.custom_dimension_1,
                    log_action.name as url,
                    sum(log_link_visit_action.time_spent) as `13`,
                    sum(case log_visit.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`
             FROM $logLinkVisitActionTable AS log_link_visit_action 
             LEFT JOIN $logVisitTable AS log_visit ON log_visit.idvisit = log_link_visit_action.idvisit 
             LEFT JOIN $logActionTable AS log_action ON log_link_visit_action.idaction_name = log_action.idaction
             WHERE ( log_link_visit_action.server_time >= ?
                 AND log_link_visit_action.server_time <= ?
                 AND log_link_visit_action.idsite = ? )
                 AND ( log_action.type = ? )",
            "bind" => array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite, $actionType));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    /**
     * visit is joined on action, then conversion is joined
     * make sure that conversion is joined on action not visit
     */
    public function test_getSelectQuery_whenJoinVisitAndConversionOnLogLinkVisitAction()
    {
        $select = '*';
        $from = 'log_link_visit_action';
        $where = false;
        $bind = array();

        $segment = 'visitServerHour==12;visitConvertedGoalId==1';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action
                    LEFT JOIN " . Common::prefixTable('log_visit') . " AS log_visit ON log_visit.idvisit = log_link_visit_action.idvisit
                    LEFT JOIN " . Common::prefixTable('log_conversion') . " AS log_conversion ON log_conversion.idvisit = log_link_visit_action.idvisit
                WHERE
                     HOUR(log_visit.visit_last_action_time) = ? AND log_conversion.idgoal = ? ",
            "bind" => array(12, 1));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    /**
     * join conversion on visit, then actions
     * make sure actions are joined before conversions
     */
    public function test_getSelectQuery_whenJoinConversionAndActionOnVisit_andPageUrlSet()
    {
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        $segment = 'visitConvertedGoalId==1;visitServerHour==12;customVariablePageName1==Test;pageUrl!=';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    log_inner.*
                FROM
                    (
                SELECT
                    log_visit.*
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                    LEFT JOIN " . Common::prefixTable('log_conversion') . " AS log_conversion ON log_conversion.idvisit = log_visit.idvisit
                WHERE
                     log_conversion.idgoal = ? AND HOUR(log_visit.visit_last_action_time) = ? AND log_link_visit_action.custom_var_k1 = ?
                      AND (
                            log_link_visit_action.idaction_url IS NOT NULL
                            AND (log_link_visit_action.idaction_url <> ''
                                OR log_link_visit_action.idaction_url = 0)
                            )
                GROUP BY log_visit.idvisit
                ORDER BY NULL
                    ) AS log_inner",
            "bind" => array(1, 12, 'Test'));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinVisitOnAction()
    {
        $actionType = 3;
        $idSite = 1;
        $select = 'count(distinct log_visit.idvisitor) AS `1`,
                   count(*) AS `2`,
                   sum(log_visit.visit_total_actions) AS `3`';
        $from  = 'log_visit';
        $where = 'log_visit.visit_last_action_time >= ?
				AND log_visit.visit_last_action_time <= ?
				AND log_visit.idsite IN (?)';
        $bind = array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite);

        $segment = 'actionType==' . $actionType;
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $logVisitTable = Common::prefixTable('log_visit');
        $logActionTable = Common::prefixTable('log_action');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');

        $expected = array(
            "sql"  => "
             SELECT count(distinct log_inner.idvisitor) AS `1`, count(*) AS `2`, sum(log_inner.visit_total_actions) AS `3` FROM ( SELECT log_visit.idvisitor, log_visit.visit_total_actions
             FROM $logVisitTable AS log_visit
                LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action
                    ON log_link_visit_action.idvisit = log_visit.idvisit
                LEFT JOIN $logActionTable AS log_action
                    ON log_link_visit_action.idaction_url = log_action.idaction
             WHERE ( log_visit.visit_last_action_time >= ?
                    AND log_visit.visit_last_action_time <= ?
                    AND log_visit.idsite IN (?) )
                    AND ( log_action.type = ? )
             GROUP BY log_visit.idvisit
             ORDER BY NULL ) AS log_inner",
            "bind" => array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite, $actionType));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinLogLinkVisitActionOnActionOnVisit()
    {
        $actionType = 3;
        $idSite = 1;
        $select = 'log_link_visit_action.custom_dimension_1,
                  actionAlias.name as url,
                  sum(log_link_visit_action.time_spent) as `13`,
                  sum(case visitAlias.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`';
        $from  = array(
            'log_link_visit_action',
             array('table' => 'log_visit', 'tableAlias' => 'visitAlias', 'joinOn' => 'visitAlias.idvisit = log_link_visit_action.idvisit'),
             array('table' => 'log_action', 'tableAlias' => 'actionAlias', 'joinOn' => 'log_link_visit_action.idaction_url = actionAlias.idaction')
        );
        $where = 'log_link_visit_action.server_time >= ?
                  AND log_link_visit_action.server_time <= ?
                  AND log_link_visit_action.idsite = ?';
        $bind = array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite);

        $segment = 'actionType==' . $actionType;
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $logVisitTable = Common::prefixTable('log_visit');
        $logActionTable = Common::prefixTable('log_action');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');
        $expected = array(
            "sql"  => "
             SELECT log_link_visit_action.custom_dimension_1,
                    actionAlias.name as url,
                    sum(log_link_visit_action.time_spent) as `13`,
                    sum(case visitAlias.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`
             FROM $logLinkVisitActionTable AS log_link_visit_action 
             LEFT JOIN $logActionTable AS log_action ON log_link_visit_action.idaction_url = log_action.idaction 
             LEFT JOIN $logVisitTable AS visitAlias ON visitAlias.idvisit = log_link_visit_action.idvisit 
             LEFT JOIN $logActionTable AS actionAlias ON log_link_visit_action.idaction_url = actionAlias.idaction
             WHERE ( log_link_visit_action.server_time >= ?
                 AND log_link_visit_action.server_time <= ?
                 AND log_link_visit_action.idsite = ? )
                 AND ( log_action.type = ? )",
            "bind" => array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite, $actionType));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    private function assertQueryDoesNotFail($query)
    {
        Db::fetchAll($query['sql'], $query['bind']);
        $this->assertTrue(true);
    }

    public function test_getSelectQuery_whenJoinLogLinkVisitActionOnAction()
    {
        $actionType = 3;
        $idSite = 1;
        $select = 'log_link_visit_action.custom_dimension_1,
                  sum(log_link_visit_action.time_spent) as `13`';
        $from  = 'log_link_visit_action';
        $where = 'log_link_visit_action.server_time >= ?
                  AND log_link_visit_action.server_time <= ?
                  AND log_link_visit_action.idsite = ?';
        $bind = array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite);

        $segment = 'actionType==' . $actionType;
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $logActionTable = Common::prefixTable('log_action');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');

        $expected = array(
            "sql"  => "
             SELECT log_link_visit_action.custom_dimension_1, sum(log_link_visit_action.time_spent) as `13`
             FROM $logLinkVisitActionTable AS log_link_visit_action
                  LEFT JOIN $logActionTable AS log_action
                        ON log_link_visit_action.idaction_url = log_action.idaction
             WHERE ( log_link_visit_action.server_time >= ?
                 AND log_link_visit_action.server_time <= ?
                 AND log_link_visit_action.idsite = ? )
                 AND ( log_action.type = ? )",
            "bind" => array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite, $actionType));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinConversionOnAction()
    {
        $actionType = 3;
        $idSite = 1;
        $select = 'log_conversion.idgoal AS `idgoal`,
                   log_conversion.custom_dimension_1 AS `custom_dimension_1`,
                   count(*) AS `1`,
                   count(distinct log_conversion.idvisit) AS `3`,';
        $from  = 'log_conversion';
        $where = 'log_conversion.server_time >= ?
				  AND log_conversion.server_time <= ?
				  AND log_conversion.idsite IN (?)';
        $bind = array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite);

        $segment = 'actionType==' . $actionType;
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $logConversionsTable = Common::prefixTable('log_conversion');
        $logActionTable = Common::prefixTable('log_action');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');

        $expected = array(
            "sql"  => "
             SELECT log_conversion.idgoal AS `idgoal`, log_conversion.custom_dimension_1 AS `custom_dimension_1`, count(*) AS `1`, count(distinct log_conversion.idvisit) AS `3`,
             FROM $logConversionsTable AS log_conversion
                  LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action
                       ON log_link_visit_action.idvisit = log_conversion.idvisit
                  LEFT JOIN $logActionTable AS log_action
                       ON log_link_visit_action.idaction_url = log_action.idaction
             WHERE ( log_conversion.server_time >= ?
                 AND log_conversion.server_time <= ?
                 AND log_conversion.idsite IN (?) )
                 AND ( log_action.type = ? )",
            "bind" => array('2015-11-30 11:00:00', '2015-12-01 10:59:59', $idSite, $actionType));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenUnionOfSegmentsAreUsed()
    {
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        $segment = 'actionUrl=@myTestUrl';
        $segment = new Segment($segment, $idSites = array());

        $logVisitTable = Common::prefixTable('log_visit');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => " SELECT log_inner.* FROM (
                          SELECT log_visit.* FROM $logVisitTable AS log_visit
                          LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action
                            ON log_link_visit_action.idvisit = log_visit.idvisit
                          WHERE (( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 1 )) )
                                OR ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 3 )) )
                                OR ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 2 )) )
                                OR ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 10 )) ) )
                        GROUP BY log_visit.idvisit ORDER BY NULL ) AS log_inner",
            "bind" => array('myTestUrl', 'myTestUrl', 'myTestUrl', 'myTestUrl'));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenJoinConversionOnLogLinkVisitAction_segmentUsesPageUrl()
    {
        $this->insertPageUrlAsAction('example.com/anypage');
        $this->insertPageUrlAsAction('example.com/anypage_bis');
        $pageUrlFoundInDb = 'example.com/page.html?hello=world';
        $actionIdFoundInDb = $this->insertPageUrlAsAction($pageUrlFoundInDb);

        $select = 'log_conversion.idgoal AS `idgoal`,
			SUM(log_conversion.items) AS `8`';

        $from = 'log_conversion';
        $where = 'log_conversion.idsite IN (?)';
        $bind = array(1);

        $segment = 'pageUrl==' . urlencode($pageUrlFoundInDb);

        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_conversion.idgoal AS `idgoal`,
                    SUM(log_conversion.items) AS `8`
                FROM
                    " . Common::prefixTable('log_conversion') . " AS log_conversion
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_conversion.idvisit
                WHERE
                    ( log_conversion.idsite IN (?) )
                    AND
                    ( log_link_visit_action.idaction_url = ? )",
            "bind" => array(1, $actionIdFoundInDb));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    /**
     * Dataprovider for test_bogusSegment_shouldThrowException
     */
    public function getBogusSegments()
    {
        return array(
            array('referrerType==not'),
            array('someRandomSegment==not'),
            array('A=B')
        );
    }

    /**
     * @dataProvider getBogusSegments
     */
    public function test_bogusSegment_shouldThrowException($segment)
    {
        try {
            new Segment($segment, $idSites = array());
        } catch (Exception $e) {
            return;
        }
        $this->fail('Expected exception not raised');
    }


    public function test_getSelectQuery_whenLimit_innerQueryShouldHaveLimitAndNoGroupBy()
    {
        $select = 'sum(log_visit.visit_total_time) as sum_visit_length';
        $from = 'log_visit';
        $where = 'log_visit.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test';
        $segment = new Segment($segment, $idSites = array());

        $orderBy = false;
        $groupBy = false;
        $limit = 33;

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy, $groupBy, $limit);

        $expected = array(
            "sql"  => "
                SELECT
                    sum(log_inner.visit_total_time) as sum_visit_length
                FROM
                    (
                SELECT
                    log_visit.visit_total_time
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE
                    ( log_visit.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? )
                ORDER BY NULL
                LIMIT 0, 33
                    ) AS log_inner",
            "bind" => array(1, 'Test'));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenLimit_withCustomJoinsAndSameColumns()
    {
        $select = "log_action_visit_entry_idaction_name.name AS 'EntryPageTitle', log_action_idaction_event_action.name AS 'EventAction', count(distinct log_visit.idvisit) AS 'nb_uniq_visits', count(distinct log_visit.idvisitor) AS 'nb_uniq_visitors', sum(case log_visit.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) AS 'bounce_count', sum(log_visit.visit_total_actions) AS 'sum_actions', sum(log_visit.visit_goal_converted) AS 'sum_visit_goal_converted'";
        $from = array('log_visit', array('table' => 'log_action', 'tableAlias' => 'log_action_visit_entry_idaction_name', 'joinOn' => 'log_visit.visit_entry_idaction_name = log_action_visit_entry_idaction_name.idaction'), 'log_link_visit_action', array('table' => 'log_action', 'tableAlias' => 'log_action_idaction_event_action', 'joinOn' => 'log_link_visit_action.idaction_event_action = log_action_idaction_event_action.idaction'));
        $where = '';
        $bind = array(1);

        $segment = '';
        $segment = new Segment($segment, $idSites = array());

        $orderBy = 'nb_uniq_visits, log_action_idaction_event_action.name';
        $groupBy = 'log_action_visit_entry_idaction_name.name, log_action_idaction_event_action.name';
        $limit = 33;

        $logVisitTable = Common::prefixTable('log_visit');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');
        $logActionTable = Common::prefixTable('log_action');

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy, $groupBy, $limit);

        $expected = array(
            "sql"  => "
                SELECT log_inner.name AS 'EntryPageTitle', log_inner.name02fd90a35677a359ea5611a4bc456a6f AS 'EventAction', count(distinct log_inner.idvisit) AS 'nb_uniq_visits', count(distinct log_inner.idvisitor) AS 'nb_uniq_visitors', sum(case log_inner.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) AS 'bounce_count', sum(log_inner.visit_total_actions) AS 'sum_actions', sum(log_inner.visit_goal_converted) AS 'sum_visit_goal_converted' 
                FROM ( 
                  SELECT log_action_visit_entry_idaction_name.name, log_action_idaction_event_action.name as name02fd90a35677a359ea5611a4bc456a6f, log_visit.idvisit, log_visit.idvisitor, log_visit.visit_total_actions, log_visit.visit_goal_converted 
                  FROM $logVisitTable AS log_visit 
                  LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit 
                  LEFT JOIN $logActionTable AS log_action_visit_entry_idaction_name ON log_visit.visit_entry_idaction_name = log_action_visit_entry_idaction_name.idaction 
                  LEFT JOIN $logActionTable AS log_action_idaction_event_action ON log_link_visit_action.idaction_event_action = log_action_idaction_event_action.idaction
                  ORDER BY nb_uniq_visits, log_action_idaction_event_action.name LIMIT 0, 33 ) 
                AS log_inner 
                GROUP BY log_inner.name, log_inner.name02fd90a35677a359ea5611a4bc456a6f 
                ORDER BY nb_uniq_visits, log_inner.name02fd90a35677a359ea5611a4bc456a6f",
            "bind" => array(1));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenLimit_withCustomJoinsAndSameColumnsAndSimilarColumns()
    {
        $select = 'log_link_visit_action.idvisit,
                   log_visit.idvisit,
                   count(log_visit.idvisit) as numvisits,
                   count(distinct log_visit.idvisit ) as numvisitors,
                   log_visit.idvisitor,
                  log_action.name as url,
                  sum(log_link_visit_action.time_spent) as `13`,
                  sum(case log_visit.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`';
        $from = array('log_visit', 'log_link_visit_action');
        $where = '';
        $bind = array(1);

        $segment = '';
        $segment = new Segment($segment, $idSites = array());

        $orderBy = 'url, log_visit.idvisit';
        $groupBy = 'log_visit.idvisit, log_visit.idvisit , log_visit.idvisitor, log_visit.idvisitor , log_link_visit_action.idvisit';
        $limit = 33;

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy, $groupBy, $limit);

        // should have replaced some idvisit columns but not idvisitor column
        $expected = array(
            "sql"  => "
                SELECT
				log_inner.idvisit,
                   log_inner.idvisit5d489886e80b4258a9407b219a4e2811,
                   count(log_inner.idvisit5d489886e80b4258a9407b219a4e2811) as numvisits,
                   count(distinct log_inner.idvisit5d489886e80b4258a9407b219a4e2811 ) as numvisitors,
                   log_inner.idvisitor,
                  log_inner.name as url,
                  sum(log_inner.time_spent) as `13`,
                  sum(case log_inner.visit_total_actions when 1 then 1 when 0 then 1 else 0 end) as `6`
			FROM
				
        (
            
			SELECT
				log_link_visit_action.idvisit, 
log_visit.idvisit as idvisit5d489886e80b4258a9407b219a4e2811, 
log_visit.idvisitor, 
log_action.name, 
log_link_visit_action.time_spent, 
log_visit.visit_total_actions
			FROM
				log_visit AS log_visit LEFT JOIN log_link_visit_action AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
			ORDER BY
				url, log_visit.idvisit LIMIT 0, 33
        ) AS log_inner
			GROUP BY
				log_inner.idvisit5d489886e80b4258a9407b219a4e2811, log_inner.idvisit5d489886e80b4258a9407b219a4e2811 , log_inner.idvisitor, log_inner.idvisitor , log_inner.idvisit
			ORDER BY
				url, log_inner.idvisit5d489886e80b4258a9407b219a4e2811",
            "bind" => array(1));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenLimitAndOffset_outerQueryShouldNotHaveOffset()
    {
        $select = 'sum(log_visit.visit_total_time) as sum_visit_length';
        $from = 'log_visit';
        $where = 'log_visit.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test';
        $segment = new Segment($segment, $idSites = array());

        $orderBy = false;
        $groupBy = false;
        $limit = 33;
        $offset = 10;

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy, $groupBy, $limit, $offset);

        $expected = array(
            "sql"  => "
                SELECT
                    sum(log_inner.visit_total_time) as sum_visit_length
                FROM
                    (
                SELECT
                    log_visit.visit_total_time
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE
                    ( log_visit.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? )
                ORDER BY NULL
                LIMIT 10, 33
                    ) AS log_inner",
            "bind" => array(1, 'Test'));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenOffsetIsZero()
    {
        $select = 'sum(log_visit.visit_total_time) as sum_visit_length';
        $from = 'log_visit';
        $where = 'log_visit.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test';
        $segment = new Segment($segment, $idSites = array());

        $orderBy = false;
        $groupBy = false;
        $limit = 33;
        $offset = 0;

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy, $groupBy, $limit, $offset);

        $expected = array(
            "sql"  => "
                SELECT
                    sum(log_inner.visit_total_time) as sum_visit_length
                FROM
                    (
                SELECT
                    log_visit.visit_total_time
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE
                    ( log_visit.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? )
                ORDER BY NULL
                LIMIT 0, 33
                    ) AS log_inner",
            "bind" => array(1, 'Test'));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenLimitIsZero()
    {
        $select = 'sum(log_visit.visit_total_time) as sum_visit_length';
        $from = 'log_visit';
        $where = 'log_visit.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test';
        $segment = new Segment($segment, $idSites = array());

        $orderBy = false;
        $groupBy = false;
        $limit = 0;
        $offset = 10;

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy, $groupBy, $limit, $offset);

        $expected = array(
            "sql"  => "
                SELECT
                    sum(log_inner.visit_total_time) as sum_visit_length
                FROM
                    (
                SELECT
                    log_visit.visit_total_time
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE
                    ( log_visit.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? )
                GROUP BY log_visit.idvisit
                ORDER BY NULL
                    ) AS log_inner",
            "bind" => array(1, 'Test'));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenPageUrlExists_asStatementAND()
    {
        $pageUrlFoundInDb = 'example.com/page.html?hello=world';

        $actionIdFoundInDb = $this->insertPageUrlAsAction($pageUrlFoundInDb);

        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        $segment = 'visitServerHour==3;pageUrl==' . urlencode($pageUrlFoundInDb);
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_inner.*
                FROM
                    (
                SELECT
                    log_visit.*
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE HOUR(log_visit.visit_last_action_time) = ?
                      AND log_link_visit_action.idaction_url = ?
                GROUP BY log_visit.idvisit
                ORDER BY NULL
                    ) AS log_inner",
            "bind" => array(3, $actionIdFoundInDb));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenPageUrlDoesNotExist_asStatementAND()
    {
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        $segment = 'visitServerHour==12;pageUrl==xyz';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_visit.*
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                WHERE HOUR(log_visit.visit_last_action_time) = ?
                      AND (1 = 0) ",
            "bind" => array(12));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenPageUrlDoesNotExist_asStatementOR()
    {
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        $segment = 'visitServerHour==12,pageUrl==xyz';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_visit.*
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                WHERE (HOUR(log_visit.visit_last_action_time) = ?
                      OR (1 = 0) )",
            "bind" => array(12));

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenPageUrlDoesNotExist_asBothStatements_OR_AND_withoutCache()
    {
        $this->disableSubqueryCache();
        $this->assertCacheWasHit($hit = 0);

        list($pageUrlFoundInDb, $actionIdFoundInDb) = $this->insertActions();

        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        /**
         * pageUrl==xyz                              -- Matches none
         * pageUrl!=abcdefg                          -- Matches all
         * pageUrl=@does-not-exist                   -- Matches none
         * pageUrl=@found-in-db                      -- Matches all
         * pageUrl=='.urlencode($pageUrlFoundInDb)   -- Matches one
         * pageUrl!@not-found                        -- matches all
         * pageUrl!@found                            -- Matches none
         */
        $segment = 'visitServerHour==12,pageUrl==xyz;pageUrl!=abcdefg,pageUrl=@does-not-exist,pageUrl=@found-in-db,pageUrl=='.urlencode($pageUrlFoundInDb).',pageUrl!@not-found,pageUrl!@found';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_inner.*
                FROM
                    (
                SELECT
                    log_visit.*
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE (HOUR(log_visit.visit_last_action_time) = ?
                        OR (1 = 0)) " . // pageUrl==xyz
                    "AND ((1 = 1) " . // pageUrl!=abcdefg
                    "    OR ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 1 )) ) " . // pageUrl=@does-not-exist
                    "    OR ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 1 )) )" . // pageUrl=@found-in-db
                    "    OR   log_link_visit_action.idaction_url = ?" . // pageUrl=='.urlencode($pageUrlFoundInDb)
                    "    OR ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name NOT LIKE CONCAT('%', ?, '%') AND type = 1 )) )" . // pageUrl!@not-found
                    "    OR ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name NOT LIKE CONCAT('%', ?, '%') AND type = 1 )) )" . // pageUrl!@found
                    " )
                GROUP BY log_visit.idvisit
                ORDER BY NULL
                    ) AS log_inner",
            "bind" => array(
                12,
                "does-not-exist",
                "found-in-db",
                $actionIdFoundInDb,
                "not-found",
                "found",
            ));

        $cache = StaticContainer::get('Piwik\Tracker\TableLogAction\Cache');
        $this->assertTrue( empty($cache->isEnabled) );
        $this->assertCacheWasHit($hit = 0);
        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenPageUrlDoesNotExist_asBothStatements_OR_AND_withCacheSave()
    {
        $this->enableSubqueryCache();

        list($pageUrlFoundInDb, $actionIdFoundInDb) = $this->insertActions();
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        /**
         * pageUrl==xyz                              -- Matches none
         * pageUrl!=abcdefg                          -- Matches all
         * pageUrl=@does-not-exist                   -- Matches none
         * pageUrl=@found-in-db                      -- Matches all
         * pageUrl=='.urlencode($pageUrlFoundInDb)   -- Matches one
         * pageUrl!@not-found                        -- matches all
         * pageUrl!@found                            -- Matches none
         */
        $segment = 'visitServerHour==12,pageUrl==xyz;pageUrl!=abcdefg,pageUrl=@does-not-exist,pageUrl=@found-in-db,pageUrl=='.urlencode($pageUrlFoundInDb).',pageUrl!@not-found,pageUrl!@found';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_inner.*
                FROM
                    (
                SELECT
                    log_visit.*
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE (HOUR(log_visit.visit_last_action_time) = ?
                        OR (1 = 0))" . // pageUrl==xyz
                "
                      AND ((1 = 1) " . // pageUrl!=abcdefg
                "
                        OR (1 = 0) " . // pageUrl=@does-not-exist
                "
                        OR ( log_link_visit_action.idaction_url IN (?,?,?) )" . // pageUrl=@found-in-db
                "
                        OR   log_link_visit_action.idaction_url = ?" . // pageUrl=='.urlencode($pageUrlFoundInDb)
                "
                        OR ( log_link_visit_action.idaction_url IN (?,?,?) )" . // pageUrl!@not-found
                "
                        OR (1 = 0) " . // pageUrl!@found
                ")
                GROUP BY log_visit.idvisit
                ORDER BY NULL
                    ) AS log_inner",
            "bind" => array(
                12,
                1, // pageUrl=@found-in-db
                2, // pageUrl=@found-in-db
                3, // pageUrl=@found-in-db
                $actionIdFoundInDb, // pageUrl=='.urlencode($pageUrlFoundInDb)
                1, // pageUrl!@not-found
                2, // pageUrl!@not-found
                3, // pageUrl!@not-found
            ));

        $cache = StaticContainer::get('Piwik\Tracker\TableLogAction\Cache');
        $this->assertTrue( !empty($cache->isEnabled) );

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    public function test_getSelectQuery_whenPageUrlDoesNotExist_asBothStatements_OR_AND_withCacheisHit()
    {
        $this->enableSubqueryCache();
        $this->assertCacheWasHit($hits = 0);

        $this->test_getSelectQuery_whenPageUrlDoesNotExist_asBothStatements_OR_AND_withCacheSave();
        $this->assertCacheWasHit($hits = 0);

        $this->test_getSelectQuery_whenPageUrlDoesNotExist_asBothStatements_OR_AND_withCacheSave();
        $this->assertCacheWasHit($hits = 4);

        $this->test_getSelectQuery_whenPageUrlDoesNotExist_asBothStatements_OR_AND_withCacheSave();
        $this->assertCacheWasHit($hits = 4 + 4);

    }


    public function test_getSelectQuery_withTwoSegments_subqueryNotCached_whenResultsetTooLarge()
    {
        $this->enableSubqueryCache();

        // do not cache when more than 3 idactions returned by subquery
        Config::getInstance()->General['segments_subquery_cache_limit'] = 2;

        list($pageUrlFoundInDb, $actionIdFoundInDb) = $this->insertActions();
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        /**
         * pageUrl=@found-in-db-bis                  -- Will be cached
         * pageUrl!@not-found                        -- Too big to cache
         */
        $segment = 'pageUrl=@found-in-db-bis;pageUrl!@not-found';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $this->assertQueryDoesNotFail($query);

        $expected = array(
            "sql"  => "
                SELECT
                    log_inner.*
                FROM
                    (
                SELECT
                    log_visit.*
                FROM
                    " . Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE
                           ( log_link_visit_action.idaction_url IN (?) )" . // pageUrl=@found-in-db-bis
                "
                        AND ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name NOT LIKE CONCAT('%', ?, '%') AND type = 1 )) ) " . // pageUrl!@not-found
                "GROUP BY log_visit.idvisit
                ORDER BY NULL
                    ) AS log_inner",
            "bind" => array(
                2, // pageUrl=@found-in-db-bis
                "not-found", // pageUrl!@not-found
            ));

        $cache = StaticContainer::get('Piwik\Tracker\TableLogAction\Cache');
        $this->assertTrue( !empty($cache->isEnabled) );

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }


    public function test_getSelectQuery_withTwoSegments_partiallyCached()
    {
        $this->assertCacheWasHit($hits = 0);

        // this will create the caches for both segments
        $this->test_getSelectQuery_withTwoSegments_subqueryNotCached_whenResultsetTooLarge();
        $this->assertCacheWasHit($hits = 0);

        // this will hit caches for both segments
        $this->test_getSelectQuery_withTwoSegments_subqueryNotCached_whenResultsetTooLarge();
        $this->assertCacheWasHit($hits = 2);
    }

    // se https://github.com/piwik/piwik/issues/9194
    public function test_getSelectQuery_whenQueryingLogConversionWithSegmentThatUsesLogLinkVisitAction_shouldUseSubselect()
    {
        $select = 'log_conversion.idgoal AS `idgoal`,
			       count(*) AS `1`,
			       count(distinct log_conversion.idvisit) AS `3`,
			       ROUND(SUM(log_conversion.revenue),2) AS `2`,
			       SUM(log_conversion.items) AS `8`';
        $from = 'log_conversion';
        $where = 'log_conversion.server_time >= ? AND log_conversion.server_time <= ? AND log_conversion.idsite IN (?)';
        $groupBy = 'log_conversion.idgoal';
        $bind = array('2015-10-14 11:00:00', '2015-10-15 10:59:59', 1);

        $segment = 'pageUrl=@/';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy = false, $groupBy);
        $this->assertQueryDoesNotFail($query);

        $logConversionTable = Common::prefixTable('log_conversion');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');

        $expectedBind = $bind;
        $expectedBind[] = '/';
        $expected = array(
            "sql"  => "
                SELECT log_inner.idgoal AS `idgoal`, count(*) AS `1`, count(distinct log_inner.idvisit) AS `3`, ROUND(SUM(log_inner.revenue),2) AS `2`, SUM(log_inner.items) AS `8`
                FROM (
                      SELECT log_conversion.idgoal, log_conversion.idvisit, log_conversion.revenue, log_conversion.items
                      FROM $logConversionTable AS log_conversion
                        LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action ON log_link_visit_action.idvisit = log_conversion.idvisit
                      WHERE ( log_conversion.server_time >= ?
                          AND log_conversion.server_time <= ?
                          AND log_conversion.idsite IN (?) )
                          AND ( ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 1 )) ) )
                      GROUP BY CONCAT(log_conversion.idvisit, '_' , log_conversion.idgoal, '_', log_conversion.buster)
                      ORDER BY NULL )
                AS log_inner GROUP BY log_inner.idgoal",
            "bind" => $expectedBind);

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    // se https://github.com/piwik/piwik/issues/9194
    public function test_getSelectQuery_whenQueryingLogConversionWithSegmentThatUsesLogLinkVisitActionAndGroupsForUsageInConversionsByTypeOfVisit_shouldUseSubselect()
    {
        $select = 'log_conversion.idgoal AS `idgoal`,
                   log_conversion.referer_type AS `referer_type`,
                   log_conversion.referer_name AS `referer_name`,
                   log_conversion.referer_keyword AS `referer_keyword`,
                   count(*) AS `1`,
                   count(distinct log_conversion.idvisit) AS `3`,
                   ROUND(SUM(log_conversion.revenue),2) AS `2`';

        $from = 'log_conversion';
        $where = 'log_conversion.server_time >= ? AND log_conversion.server_time <= ? AND log_conversion.idsite IN (?)';
        $groupBy = 'log_conversion.idgoal, log_conversion.referer_type, log_conversion.referer_name, log_conversion.referer_keyword';
        $bind = array('2015-10-14 11:00:00', '2015-10-15 10:59:59', 1);

        $segment = 'pageUrl=@/';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy = false, $groupBy);
        $this->assertQueryDoesNotFail($query);

        $logConversionTable = Common::prefixTable('log_conversion');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');

        $expectedBind = $bind;
        $expectedBind[] = '/';
        $expected = array(
            "sql"  => "
                SELECT log_inner.idgoal AS `idgoal`,
                   log_inner.referer_type AS `referer_type`,
                   log_inner.referer_name AS `referer_name`,
                   log_inner.referer_keyword AS `referer_keyword`,
                   count(*) AS `1`,
                   count(distinct log_inner.idvisit) AS `3`,
                   ROUND(SUM(log_inner.revenue),2) AS `2`
                FROM (
                      SELECT log_conversion.idgoal, log_conversion.referer_type, log_conversion.referer_name, log_conversion.referer_keyword, log_conversion.idvisit, log_conversion.revenue
                      FROM $logConversionTable AS log_conversion
                        LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action ON log_link_visit_action.idvisit = log_conversion.idvisit
                      WHERE ( log_conversion.server_time >= ?
                          AND log_conversion.server_time <= ?
                          AND log_conversion.idsite IN (?) )
                          AND ( ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 1 )) ) )
                      GROUP BY CONCAT(log_conversion.idvisit, '_' , log_conversion.idgoal, '_', log_conversion.buster)
                      ORDER BY NULL )
                AS log_inner GROUP BY log_inner.idgoal, log_inner.referer_type, log_inner.referer_name, log_inner.referer_keyword",
            "bind" => $expectedBind);

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    // see https://github.com/piwik/piwik/issues/9194
    public function test_getSelectQuery_whenQueryingLogConversionWithSegmentThatUsesLogLinkVisitActionAndLogVisit_shouldUseSubselectGroupedByIdVisitAndBuster()
    {
        $select = 'log_conversion.idgoal AS `idgoal`,
			       count(*) AS `1`,
			       count(distinct log_conversion.idvisit) AS `3`,
			       ROUND(SUM(log_conversion.revenue),2) AS `2`';

        $from = 'log_conversion';
        $where = 'log_conversion.server_time >= ? AND log_conversion.server_time <= ? AND log_conversion.idsite IN (?)';
        $groupBy = 'log_conversion.idgoal';
        $bind = array('2015-10-14 11:00:00', '2015-10-15 10:59:59', 1);

        $segment = 'visitorType==returning,visitorType==returningCustomer;pageUrl=@/';
        $segment = new Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy = false, $groupBy);
        $this->assertQueryDoesNotFail($query);

        $logConversionTable = Common::prefixTable('log_conversion');
        $logLinkVisitActionTable = Common::prefixTable('log_link_visit_action');
        $logVisitTable = Common::prefixTable('log_visit');

        $expectedBind = $bind;
        $expectedBind[] = 1;
        $expectedBind[] = 2;
        $expectedBind[] = '/';
        $expected = array(
            "sql"  => "
                SELECT log_inner.idgoal AS `idgoal`, count(*) AS `1`, count(distinct log_inner.idvisit) AS `3`, ROUND(SUM(log_inner.revenue),2) AS `2`
                FROM (
                    SELECT log_conversion.idgoal, log_conversion.idvisit, log_conversion.revenue
                    FROM $logConversionTable AS log_conversion
                       LEFT JOIN $logLinkVisitActionTable AS log_link_visit_action ON log_link_visit_action.idvisit = log_conversion.idvisit
                       LEFT JOIN $logVisitTable AS log_visit ON log_visit.idvisit = log_conversion.idvisit
                    WHERE ( log_conversion.server_time >= ?
                        AND log_conversion.server_time <= ?
                        AND log_conversion.idsite IN (?) )
                        AND ( (log_visit.visitor_returning = ?
                        OR log_visit.visitor_returning = ?)
                        AND ( log_link_visit_action.idaction_url IN (SELECT idaction FROM log_action WHERE ( name LIKE CONCAT('%', ?, '%') AND type = 1 )) ) )
                    GROUP BY CONCAT(log_conversion.idvisit, '_' , log_conversion.idgoal, '_', log_conversion.buster)
                    ORDER BY NULL )
                AS log_inner
                GROUP BY log_inner.idgoal",
            "bind" => $expectedBind);

        $this->assertEquals($this->removeExtraWhiteSpaces($expected), $this->removeExtraWhiteSpaces($query));
    }

    /**
     * @param $pageUrlFoundInDb
     * @return string
     * @throws Exception
     */
    private function insertPageUrlAsAction($pageUrlFoundInDb)
    {
        TableLogAction::loadIdsAction(array(
            'idaction_url' => array($pageUrlFoundInDb, Action::TYPE_PAGE_URL)
        ));

        $actionIdFoundInDb = Db::fetchOne("SELECT idaction from " . Common::prefixTable('log_action') . " WHERE name = ?", $pageUrlFoundInDb);
        $this->assertNotEmpty($actionIdFoundInDb, "Action $pageUrlFoundInDb was not found in the " . Common::prefixTable('log_action') . " table.");
        return $actionIdFoundInDb;
    }

    /**
     * @return array
     */
    private function insertActions()
    {
        $pageUrlFoundInDb = 'example.com/found-in-db';
        $actionIdFoundInDb = $this->insertPageUrlAsAction($pageUrlFoundInDb);

        // Adding some other actions to make test case more realistic
        $this->insertPageUrlAsAction('example.net/found-in-db-bis');
        $this->insertPageUrlAsAction('example.net/found-in-db-ter');

        return array($pageUrlFoundInDb, $actionIdFoundInDb);
    }

    private function assertCacheWasHit($expectedHits)
    {
        $hits = $this->tableLogActionCacheHits;
        $this->assertEquals($expectedHits, $hits,
            "expected cache was hit $expectedHits time(s), but got $hits cache hits instead.");
    }

    private function disableSubqueryCache()
    {
        Config::getInstance()->General['enable_segments_subquery_cache'] = 0;
    }

    private function enableSubqueryCache()
    {
        Config::getInstance()->General['enable_segments_subquery_cache'] = 1;
    }

    public function provideContainerConfig()
    {
        $self = $this;

        $cacheProxy = $this->getMockBuilder('Piwik\Cache\Lazy')
                           ->setMethods(array('fetch', 'contains', 'save', 'delete', 'flushAll'))
                           ->disableOriginalConstructor()
                           ->getMock();

        $cacheProxy->expects($this->any())->method('fetch')->willReturnCallback(function ($id) {
            $realCache = StaticContainer::get('Piwik\Cache\Lazy');
            return $realCache->fetch($id);
        });
        $cacheProxy->expects($this->any())->method('contains')->willReturnCallback(function ($id) use ($self) {
            $realCache = StaticContainer::get('Piwik\Cache\Lazy');

            $result = $realCache->contains($id);
            if ($result) {
                ++$self->tableLogActionCacheHits;
            }

            return $result;
        });
        $cacheProxy->expects($this->any())->method('save')->willReturnCallback(function ($id, $data, $lifetime = 0) {
            $realCache = StaticContainer::get('Piwik\Cache\Lazy');
            return $realCache->save($id, $data, $lifetime);
        });
        $cacheProxy->expects($this->any())->method('delete')->willReturnCallback(function ($id) {
            $realCache = StaticContainer::get('Piwik\Cache\Lazy');
            return $realCache->delete($id);
        });
        $cacheProxy->expects($this->any())->method('flushAll')->willReturnCallback(function () {
            $realCache = StaticContainer::get('Piwik\Cache\Lazy');
            return $realCache->flushAll();
        });

        return array(
            'Piwik\Access' => new FakeAccess(),
            'Piwik\Tracker\TableLogAction\Cache' => \DI\object()->constructorParameter('cache', $cacheProxy),
        );
    }

    public function test_willBeArchived_ByDefault_AllSegmentsWillBeArchived()
    {
        $this->assertWillBeArchived($this->exampleSegment);
    }

    public function test_willBeArchived_SegmentsWillNotBeArchivedWhenBrowserArchivingIsDisabledAndNoSuchSegmentExists()
    {
        $this->disableSegmentBrowserArchiving();
        $this->assertNotWillBeArchived($this->exampleSegment);
    }

    public function test_willBeArchived_SegmentsWillBeArchivedWhenBrowserArchivingIsDisabledButBrowserSegmentsArchivingEnabled()
    {
        $this->disableBrowserArchiving();
        $this->assertWillBeArchived($this->exampleSegment);
    }

    public function test_willSegmentBeArchived_SegmentsWillNotBeArchivedWhenBrowserArchivingDisabledAndSegmentExistsNotAutoArchive()
    {
        $this->disableSegmentBrowserArchiving();

        SegmentEditorApi::getInstance()->add('My Name', $this->exampleSegment, $idSite = false, $autoArchive = false);

        $this->assertNotWillBeArchived($this->exampleSegment);
    }

    public function test_willSegmentBeArchived_SegmentsWillBeArchivedWhenBrowserArchivingDisabledButSegmentExistsWithAuthoArchive()
    {
        $this->disableSegmentBrowserArchiving();

        SegmentEditorApi::getInstance()->add('My Name', $this->exampleSegment, $idSite = false, $autoArchive = true);

        $this->assertWillBeArchived($this->exampleSegment);
    }

    public function test_willBeArchived_AnEmptySegmentShouldBeAlwaysArchived()
    {
        $this->assertWillBeArchived(false);

        $this->disableSegmentBrowserArchiving();
        $this->assertWillBeArchived(false);
    }

    private function assertWillBeArchived($segmentString)
    {
        $this->assertTrue($this->willSegmentByArchived($segmentString));
    }

    private function assertNotWillBeArchived($segmentString)
    {
        $this->assertFalse($this->willSegmentByArchived($segmentString));
    }

    private function willSegmentByArchived($segmentString)
    {
        $segment = new Segment($segmentString, $idSites = array(1));

        return $segment->willBeArchived();
    }

    private function disableBrowserArchiving()
    {
        Rules::setBrowserTriggerArchiving(false);
    }

    private function disableSegmentBrowserArchiving()
    {
        $this->disableBrowserArchiving();
        $config = Config::getInstance();
        $general = $config->General;
        $general['browser_archiving_disabled_enforce'] = '1';
        $config->General = $general;
    }
}
