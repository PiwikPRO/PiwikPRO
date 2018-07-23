<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Unit\Report;

use Piwik\Report\ReportWidgetConfig;

/**
 * @group Widget
 * @group Report
 * @group ReportWidgetConfig
 * @group ReportWidgetConfigTest
 */
class ReportWidgetConfigTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ReportWidgetConfig
     */
    private $config;

    public function setUp()
    {
        parent::setUp();
        $this->config = new ReportWidgetConfig();
    }

    public function test_getViewDataTable_ByDefaultThereShouldBeNoDefaultView()
    {
        $this->assertNull($this->config->getViewDataTable());
    }

    public function test_setDefaultViewDataTable()
    {
        $this->config->setDefaultViewDataTable('table');

        $this->assertSame('table', $this->config->getViewDataTable());
        $this->assertFalse($this->config->isViewDataTableForced());
    }

    public function test_forceViewDataTable()
    {
        $this->config->forceViewDataTable('table');

        $this->assertSame('table', $this->config->getViewDataTable());
        $this->assertTrue($this->config->isViewDataTableForced());
    }

    public function test_name_set_get()
    {
        $this->config->setName('testName');

        $this->assertSame('testName', $this->config->getName());
    }

    public function test_getName_shouldBeEmptyStringByDefault()
    {
        $this->assertSame('', $this->config->getName());
    }

    public function test_categoryId_set_get()
    {
        $this->config->setCategoryId('testCat');

        $this->assertSame('testCat', $this->config->getCategoryId());
    }

    public function test_getCategoryId_shouldBeEmptyStringByDefault()
    {
        $this->assertSame('', $this->config->getCategoryId());
    }

    public function test_subcategoryId_set_get()
    {
        $this->config->setSubcategoryId('testsubcat');

        $this->assertSame('testsubcat', $this->config->getSubcategoryId());
    }

    public function test_getSubcategoryId_shouldBeEmptyStringByDefault()
    {
        $this->assertSame('', $this->config->getSubcategoryId());
    }

    public function test_module_set_get()
    {
        $this->config->setModule('CoreHome');

        $this->assertSame('CoreHome', $this->config->getModule());
    }

    public function test_getModule_shouldBeEmptyStringByDefault()
    {
        $this->assertSame('', $this->config->getModule());
    }

    public function test_action_set_get()
    {
        $this->config->setAction('get');

        $this->assertSame('get', $this->config->getAction());
    }

    public function test_getAction_shouldBeEmptyStringByDefault()
    {
        $this->assertSame('', $this->config->getAction());
    }

    public function test_order_set_get()
    {
        $this->config->setOrder(99);
        $this->assertSame(99, $this->config->getOrder());

        $this->config->setOrder('98');
        $this->assertSame(98, $this->config->getOrder());
    }

    public function test_getOrder_shouldReturnADefaultValue()
    {
        $this->assertSame(99, $this->config->getOrder());
    }

    public function test_setMiddlewareParameters_set_get()
    {
        $this->config->setMiddlewareParameters(array(
            'module' => 'Goals',
            'action' => 'hasConversions'
        ));

        $this->assertSame(array(
            'module' => 'Goals',
            'action' => 'hasConversions'
        ), $this->config->getMiddlewareParameters());
    }

    public function test_getMiddlewareParameters_shouldReturnADefaultValue()
    {
        $this->assertSame(array(), $this->config->getMiddlewareParameters());
    }

    public function test_getParameters_ShouldAddModuleAndAction()
    {
        $this->setModuleAndAction();
        $this->assertSame(array('module' => 'CoreHome', 'action' => 'renderMe'), $this->config->getParameters());
    }

    public function test_getParameters_ShouldNotBePossibleToOverwriteModuleAndAction()
    {
        $this->setModuleAndAction();
        $this->config->setParameters(array('module' => 'Actions', 'action' => 'index'));

        $this->assertSame(array('module' => 'CoreHome', 'action' => 'renderMe'), $this->config->getParameters());
    }

    public function test_getParameters_ShouldNotReturnViewDataTableIfItIsNotForced()
    {
        $this->setModuleAndAction();
        $this->config->setDefaultViewDataTable('graph');

        $this->assertSame(array('module' => 'CoreHome', 'action' => 'renderMe'), $this->config->getParameters());
    }

    public function test_getParameters_ShouldForceViewDataTableIfSet()
    {
        $this->setModuleAndAction();
        $this->config->forceViewDataTable('graph');

        $this->assertSame(array('forceView' => '1', 'viewDataTable' => 'graph', 'module' => 'CoreHome', 'action' => 'renderMe'), $this->config->getParameters());
    }

    public function test_addParameters_ShouldAddMoreParams()
    {
        $this->setModuleAndAction();
        $this->config->addParameters(array('test' => '1')); // should be removed by setParameters
        $this->config->addParameters(array('forceView' => '1'));
        $this->config->addParameters(array('test' => '3'));

        $this->assertSame(array('module' => 'CoreHome', 'action' => 'renderMe', 'test' => '3', 'forceView' => '1'), $this->config->getParameters());
    }

    public function test_setParameters_ShouldOverwriteAnyExistingParameters()
    {
        $this->setModuleAndAction();
        $this->config->addParameters(array('test' => '1')); // should be removed by setParameters
        $this->config->setParameters(array('forceView' => '1'));

        $this->assertSame(array('module' => 'CoreHome', 'action' => 'renderMe', 'forceView' => '1'), $this->config->getParameters());
    }

    public function test_shouldBeEnabledByDefault()
    {
        $this->assertTrue($this->config->isEnabled());
    }

    public function test_enable_disable()
    {
        $this->config->disable();
        $this->assertFalse($this->config->isEnabled());
        $this->config->enable();
        $this->assertTrue($this->config->isEnabled());
    }

    public function test_setIsEnabled()
    {
        $this->config->setIsEnabled(false);
        $this->assertFalse($this->config->isEnabled());
        $this->config->setIsEnabled(true);
        $this->assertTrue($this->config->isEnabled());
    }

    public function test_checkIsEnabled_shouldNotThrowException_IfEnabled()
    {
        $this->config->enable();
        $this->config->checkIsEnabled();
    }

    /**
     * @expectedException \Exception
     */
    public function test_checkIsEnabled_shouldThrowException_IfDisabled()
    {
        $this->config->disable();
        $this->config->checkIsEnabled();
    }

    public function test_shouldBeWidgetizable_ByDefault()
    {
        $this->assertTrue($this->config->isWidgetizeable());
    }

    public function test_widgetizeable()
    {
        $this->config->setIsNotWidgetizable();
        $this->assertFalse($this->config->isWidgetizeable());
        $this->config->setIsWidgetizable();
        $this->assertTrue($this->config->isWidgetizeable());
    }

    public function test_getUniqueId_withNoParameters()
    {
        $this->setModuleAndAction();
        $this->assertSame('widgetCoreHomerenderMe', $this->config->getUniqueId());
    }

    public function test_getUniqueId_withParameters()
    {
        $this->setModuleAndAction();
        $this->config->addParameters(array('viewDataTable' => 'table', 'forceView' => '1', 'mtest' => array('test')));
        $this->assertSame('widgetCoreHomerenderMeviewDataTabletableforceView1mtestArray', $this->config->getUniqueId());
    }

    private function setModuleAndAction()
    {
        $this->config->setModule('CoreHome');
        $this->config->setAction('renderMe');
    }

}
