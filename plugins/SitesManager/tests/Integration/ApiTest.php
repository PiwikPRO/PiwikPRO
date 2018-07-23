<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\SitesManager\tests\Integration;

use Piwik\Container\StaticContainer;
use Piwik\Piwik;
use Piwik\Plugin;
use Piwik\Plugins\MobileAppMeasurable;
use Piwik\Plugins\MobileAppMeasurable\Type;
use Piwik\Plugins\WebsiteMeasurable\Type as WebsiteType;
use Piwik\Plugins\SitesManager\API;
use Piwik\Plugins\SitesManager\Model;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\Measurable\Measurable;
use Piwik\Site;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Exception;
use PHPUnit_Framework_Constraint_IsType;

/**
 * Class Plugins_SitesManagerTest
 *
 * @group Plugins
 * @group ApiTest
 * @group SitesManager
 */
class ApiTest extends IntegrationTestCase
{
    public function setUp()
    {
        parent::setUp();

        Plugin\Manager::getInstance()->activatePlugin('MobileAppMeasurable');

        // setup the access layer
        FakeAccess::$superUser = true;
    }

    /**
     * empty name -> exception
     * @expectedException \Exception
     */
    public function test_addSite_WithEmptyName_ThrowsException()
    {
        API::getInstance()->addSite("", array("http://piwik.net"));
    }

    /**
     * DataProvider for testAddSiteWrongUrls
     */
    public function getInvalidUrlData()
    {
        return array(
            array(array()), // no urls
            array(array("")),
            array(""),
            array("5http://piwik.net"),
            array("???://piwik.net"),
        );
    }

    /**
     * wrong urls -> exception
     *
     * @dataProvider getInvalidUrlData
     * @expectedException \Exception
     */
    public function test_addSite_WithWrongUrls_ThrowsException($url)
    {
        API::getInstance()->addSite("name", $url);
    }

    /**
     * Test with valid IPs
     */
    public function test_addSite_WithExcludedIps_AndTimezone_AndCurrency_AndExcludedQueryParameters_SucceedsWhenParamsAreValid()
    {
        $this->addSiteTest($expectedWebsiteType = 'mobile-\'app');
    }

    /**
     * @dataProvider getDifferentTypesDataProvider
     */
    public function test_addSite_WhenTypeIsKnown($expectedWebsiteType)
    {
        $this->addSiteTest($expectedWebsiteType);
    }

    private function addSiteTest($expectedWebsiteType, $settingValues = null)
    {
        $ips = '1.2.3.4,1.1.1.*,1.2.*.*,1.*.*.*';
        $timezone = 'Europe/Paris';
        $currency = 'EUR';
        $excludedQueryParameters = 'p1,P2, P33333';
        $expectedExcludedQueryParameters = 'p1,P2,P33333';
        $excludedUserAgents = " p1,P2, \nP3333 ";
        $expectedExcludedUserAgents = "p1,P2,P3333";
        $keepUrlFragment = 1;
        $idsite = API::getInstance()->addSite("name", "http://piwik.net/", $ecommerce = 1,
            $siteSearch = 1, $searchKeywordParameters = 'search,param', $searchCategoryParameters = 'cat,category',
            $ips, $excludedQueryParameters, $timezone, $currency, $group = null, $startDate = null, $excludedUserAgents,
            $keepUrlFragment, $expectedWebsiteType, $settingValues);
        $siteInfo = API::getInstance()->getSiteFromId($idsite);
        $this->assertEquals($ips, $siteInfo['excluded_ips']);
        $this->assertEquals($timezone, $siteInfo['timezone']);
        $this->assertEquals($currency, $siteInfo['currency']);
        $this->assertEquals($ecommerce, $siteInfo['ecommerce']);
        $this->assertTrue(Site::isEcommerceEnabledFor($idsite));
        $this->assertEquals($siteSearch, $siteInfo['sitesearch']);
        $this->assertTrue(Site::isSiteSearchEnabledFor($idsite));
        $this->assertEquals($expectedWebsiteType, $siteInfo['type']);
        $this->assertEquals($expectedWebsiteType, Site::getTypeFor($idsite));

        $this->assertEquals($searchKeywordParameters, $siteInfo['sitesearch_keyword_parameters']);
        $this->assertEquals($searchCategoryParameters, $siteInfo['sitesearch_category_parameters']);
        $this->assertEquals($expectedExcludedQueryParameters, $siteInfo['excluded_parameters']);
        $this->assertEquals($expectedExcludedUserAgents, $siteInfo['excluded_user_agents']);

        return $siteInfo;
    }

    /**
     * dataProvider for testAddSiteExcludedIpsNotValid
     */
    public function getInvalidIPsData()
    {
        return array(
            array('35817587341'),
            array('ieagieha'),
            array('1.2.3'),
            array('*.1.1.1'),
            array('*.*.1.1'),
            array('*.*.*.1'),
            array('1.1.1.1.1'),
        );
    }

    /**
     * Test with invalid IPs
     *
     * @dataProvider getInvalidIPsData
     * @expectedException \Exception
     */
    public function test_addSite_WithInvalidExcludedIps_ThrowsException($ip)
    {
        API::getInstance()->addSite("name", "http://piwik.net/", $ecommerce = 0,
            $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, $ip);
    }

    /**
     * one url -> one main_url and nothing inserted as alias urls
     */
    public function test_addSite_WithOneUrl_Succeeds_AndCreatesNoAliasUrls()
    {
        $url = "http://piwik.net/";
        $urlOK = "http://piwik.net";
        $idsite = API::getInstance()->addSite("name", $url);
        $this->assertInternalType(PHPUnit_Framework_Constraint_IsType::TYPE_INT, $idsite);

        $siteInfo = API::getInstance()->getSiteFromId($idsite);
        $this->assertEquals($urlOK, $siteInfo['main_url']);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($siteInfo['ts_created'])));

        $siteUrls = API::getInstance()->getSiteUrlsFromId($idsite);
        $this->assertEquals(1, count($siteUrls));
    }

    /**
     * several urls -> one main_url and others as alias urls
     */
    public function test_addSite_WithSeveralUrls_Succeeds_AndCreatesAliasUrls()
    {
        $urls = array("http://piwik.net/", "http://piwik.com", "https://piwik.net/test/", "piwik.net/another/test");
        $urlsOK = array("http://piwik.net", "http://piwik.com", "http://piwik.net/another/test", "https://piwik.net/test");
        $idsite = API::getInstance()->addSite("super website", $urls);
        $this->assertInternalType(PHPUnit_Framework_Constraint_IsType::TYPE_INT, $idsite);

        $siteInfo = API::getInstance()->getSiteFromId($idsite);
        $this->assertEquals($urlsOK[0], $siteInfo['main_url']);

        $siteUrls = API::getInstance()->getSiteUrlsFromId($idsite);
        $this->assertEquals($urlsOK, $siteUrls);
    }

    /**
     * strange name
     */
    public function test_addSite_WithStrangeName_Succeeds()
    {
        $name = "supertest(); ~@@()''!£\$'%%^'!£ போ";
        $idsite = API::getInstance()->addSite($name, "http://piwik.net");
        $this->assertInternalType(PHPUnit_Framework_Constraint_IsType::TYPE_INT, $idsite);

        $siteInfo = API::getInstance()->getSiteFromId($idsite);
        $this->assertEquals($name, $siteInfo['name']);

    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage SitesManager_OnlyMatchedUrlsAllowed
     * @dataProvider getDifferentTypesDataProvider
     */
    public function test_addSite_ShouldFailAndNotCreatedASite_IfASettingIsInvalid($type)
    {
        try {
            $settings = array('WebsiteMeasurable' => array(array('name' => 'exclude_unknown_urls', 'value' => 'fooBar')));
            $this->addSiteWithType($type, $settings);
        } catch (Exception $e) {

            // make sure no site created
            $ids = API::getInstance()->getAllSitesId();
            $this->assertEquals(array(), $ids);

            throw $e;
        }
    }

    public function test_addSite_ShouldSavePassedMeasurableSettings_IfSettingsAreValid()
    {
        $type = WebsiteType::ID;
        $settings = array('WebsiteMeasurable' => array(array('name' => 'urls', 'value' => array('http://www.piwik.org'))));
        $idSite = $this->addSiteWithType($type, $settings);

        $this->assertSame(1, $idSite);

        $settings = $this->getWebsiteMeasurable($idSite);
        $urls = $settings->urls->getValue();

        $this->assertSame(array('http://www.piwik.org'), $urls);
    }

    /**
     * @return \Piwik\Plugins\WebsiteMeasurable\MeasurableSettings
     */
    private function getWebsiteMeasurable($idSite)
    {
        $settings = StaticContainer::get('Piwik\Plugin\SettingsProvider');
        return $settings->getMeasurableSettings('WebsiteMeasurable', $idSite, null);
    }

    /**
     * adds a site
     * use by several other unit tests
     */
    protected function _addSite()
    {
        $name = "website ";
        $idsite = API::getInstance()->addSite($name, array("http://piwik.net", "http://piwik.com/test/"));
        $this->assertInternalType(PHPUnit_Framework_Constraint_IsType::TYPE_INT, $idsite);

        $siteInfo = API::getInstance()->getSiteFromId($idsite);
        $this->assertEquals($name, $siteInfo['name']);
        $this->assertEquals("http://piwik.net", $siteInfo['main_url']);

        $siteUrls = API::getInstance()->getSiteUrlsFromId($idsite);
        $this->assertEquals(array("http://piwik.net", "http://piwik.com/test"), $siteUrls);

        return $idsite;
    }

    private function addSiteWithType($type, $settings)
    {
        return API::getInstance()->addSite("name", "http://piwik.net/", $ecommerce = 0,
            $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null,
            $ip = null,
            $excludedQueryParameters = null,
            $timezone = null,
            $currency = null,
            $group = null,
            $startDate = null,
            $excludedUserAgents = null,
            $keepURLFragments = null,
            $type, $settings);
    }

    private function updateSiteSettings($idSite, $newSiteName, $settings)
    {
        return API::getInstance()->updateSite($idSite,
            $newSiteName,
            $urls = null,
            $ecommerce = null,
            $siteSearch = null,
            $searchKeywordParameters = null,
            $searchCategoryParameters = null,
            $excludedIps = null,
            $excludedQueryParameters = null,
            $timezone = null,
            $currency = null,
            $group = null,
            $startDate = null,
            $excludedUserAgents = null,
            $keepURLFragments = null,
            $type = null,
            $settings);
    }

    /**
     * no duplicate -> all the urls are saved
     */
    public function test_addSiteAliasUrls_WithUniqueUrls_SavesAllUrls()
    {
        $idsite = $this->_addSite();

        $siteUrlsBefore = API::getInstance()->getSiteUrlsFromId($idsite);

        $toAdd = array("http://piwik1.net",
                       "http://piwik2.net",
                       "http://piwik3.net/test/",
                       "http://localhost/test",
                       "http://localho5.st/test",
                       "http://l42578gqege.f4",
                       "http://super.com/test/test/atqata675675/te"
        );
        $toAddValid = array("http://piwik1.net",
                            "http://piwik2.net",
                            "http://piwik3.net/test",
                            "http://localhost/test",
                            "http://localho5.st/test",
                            "http://l42578gqege.f4",
                            "http://super.com/test/test/atqata675675/te");

        $insertedUrls = API::getInstance()->addSiteAliasUrls($idsite, $toAdd);
        $this->assertEquals(count($toAdd), $insertedUrls);

        $siteUrlsAfter = API::getInstance()->getSiteUrlsFromId($idsite);

        $shouldHave = array_merge($siteUrlsBefore, $toAddValid);
        sort($shouldHave);

        sort($siteUrlsAfter);

        $this->assertEquals($shouldHave, $siteUrlsAfter);
    }

    /**
     * duplicate -> don't save the already existing URLs
     */
    public function test_addSiteAliasUrls_WithDuplicateUrls_RemovesDuplicatesBeforeSaving()
    {
        $idsite = $this->_addSite();

        $siteUrlsBefore = API::getInstance()->getSiteUrlsFromId($idsite);

        $toAdd = array_merge($siteUrlsBefore, array("http://piwik1.net", "http://piwik2.net"));

        $insertedUrls = API::getInstance()->addSiteAliasUrls($idsite, $toAdd);
        $this->assertEquals(count($toAdd) - count($siteUrlsBefore), $insertedUrls);

        $siteUrlsAfter = API::getInstance()->getSiteUrlsFromId($idsite);

        $shouldHave = $toAdd;
        sort($shouldHave);

        sort($siteUrlsAfter);

        $this->assertEquals($shouldHave, $siteUrlsAfter);
    }

    /**
     * case empty array => nothing happens
     */
    public function test_addSiteAliasUrls_WithNoUrls_DoesNothing()
    {
        $idsite = $this->_addSite();

        $siteUrlsBefore = API::getInstance()->getSiteUrlsFromId($idsite);

        $toAdd = array();

        $insertedUrls = API::getInstance()->addSiteAliasUrls($idsite, $toAdd);
        $this->assertEquals(count($toAdd), $insertedUrls);

        $siteUrlsAfter = API::getInstance()->getSiteUrlsFromId($idsite);

        $shouldHave = $siteUrlsBefore;
        sort($shouldHave);

        sort($siteUrlsAfter);

        $this->assertEquals($shouldHave, $siteUrlsAfter);
    }

    /**
     * case array only duplicate => nothing happens
     */
    public function test_addSiteAliasUrls_WithAlreadyPersistedUrls_DoesNothing()
    {
        $idsite = $this->_addSite();

        $siteUrlsBefore = API::getInstance()->getSiteUrlsFromId($idsite);

        $toAdd = $siteUrlsBefore;

        $insertedUrls = API::getInstance()->addSiteAliasUrls($idsite, $toAdd);
        $this->assertEquals(0, $insertedUrls);

        $siteUrlsAfter = API::getInstance()->getSiteUrlsFromId($idsite);

        $shouldHave = $siteUrlsBefore;
        sort($shouldHave);

        sort($siteUrlsAfter);

        $this->assertEquals($shouldHave, $siteUrlsAfter);
    }

    /**
     * wrong format urls => exception
     * @expectedException \Exception
     */
    public function test_addSiteAliasUrls_WithIncorrectFormat_ThrowsException_3()
    {
        $idsite = $this->_addSite();
        $toAdd = array("http:mpigeq");
        API::getInstance()->addSiteAliasUrls($idsite, $toAdd);
    }

    /**
     * wrong idsite => no exception because simply no access to this resource
     * @expectedException \Exception
     */
    public function test_addSiteAliasUrls_WithWrongIdSite_ThrowsException()
    {
        $toAdd = array("http://pigeq.com/test");
        API::getInstance()->addSiteAliasUrls(-1, $toAdd);
    }

    /**
     * wrong idsite => exception
     * @expectedException \Exception
     */
    public function test_addSiteAliasUrls_WithWrongIdSite_ThrowsException2()
    {
        $toAdd = array("http://pigeq.com/test");
        API::getInstance()->addSiteAliasUrls(155, $toAdd);
    }

    public function test_addSite_CorrectlySavesExcludeUnknownUrlsSetting()
    {
        $idSite = API::getInstance()->addSite("site", array("http://piwik.net"), $ecommerce = null, $siteSearch = null,
            $searchKeywordParams = null, $searchCategoryParams = null, $excludedIps = null, $excludedQueryParams = null,
            $timezone = null, $currency = null, $group = null, $startDate = null, $excludedUserAgents = null,
            $keepUrlFragments = null, $type = null, $settings = null, $excludeUnknownUrls = true);

        $site = API::getInstance()->getSiteFromId($idSite);
        $this->assertEquals(1, $site['exclude_unknown_urls']);
    }

    /**
     * no Id -> empty array
     */
    public function test_getAllSitesId_ReturnsNothing_WhenNoSitesSaved()
    {
        $ids = API::getInstance()->getAllSitesId();
        $this->assertEquals(array(), $ids);
    }

    /**
     * several Id -> normal array
     */
    public function test_getAllSitesId_ReturnsAllIds_WhenMultipleSitesPersisted()
    {
        $name = "tetq";
        $idsites = array(
            API::getInstance()->addSite($name, array("http://piwik.net", "http://piwik.com/test/")),
            API::getInstance()->addSite($name, array("http://piwik.net", "http://piwik.com/test/")),
            API::getInstance()->addSite($name, array("http://piwik.net", "http://piwik.com/test/")),
            API::getInstance()->addSite($name, array("http://piwik.net", "http://piwik.com/test/")),
            API::getInstance()->addSite($name, array("http://piwik.net", "http://piwik.com/test/")),
        );

        $ids = API::getInstance()->getAllSitesId();
        $this->assertEquals($idsites, $ids);
    }

    /**
     * wrong id => exception
     * @expectedException \Exception
     */
    public function test_getSiteFromId_WithWrongId_ThrowsException1()
    {
        API::getInstance()->getSiteFromId(0);
    }

    /**
     * wrong id => exception
     * @expectedException \Exception
     */
    public function test_getSiteFromId_WithWrongId_ThrowsException2()
    {
        API::getInstance()->getSiteFromId("x1");
    }

    /**
     * wrong id : no access => exception
     * @expectedException \Exception
     */
    public function test_getSiteFromId_ThrowsException_WhenTheUserDoesNotHavaAcessToTheSite()
    {
        $idsite = API::getInstance()->addSite("site", array("http://piwik.net", "http://piwik.com/test/"));
        $this->assertEquals(1, $idsite);

        // set noaccess to site 1
        FakeAccess::setIdSitesView(array(2));
        FakeAccess::setIdSitesAdmin(array());

        API::getInstance()->getSiteFromId(1);
    }

    /**
     * normal case
     */
    public function test_getSiteFromId_WithNormalId_ReturnsTheCorrectSite()
    {
        $name = "website ''";
        $idsite = API::getInstance()->addSite($name, array("http://piwik.net", "http://piwik.com/test/"));
        $this->assertInternalType(PHPUnit_Framework_Constraint_IsType::TYPE_INT, $idsite);

        $siteInfo = API::getInstance()->getSiteFromId($idsite);
        $this->assertEquals($name, $siteInfo['name']);
        $this->assertEquals("http://piwik.net", $siteInfo['main_url']);
    }

    /**
     * there is no admin site available -> array()
     */
    public function test_getSitesWithAdminAccess_ReturnsNothing_WhenUserHasNoAdminAccess()
    {
        FakeAccess::setIdSitesAdmin(array());

        $sites = API::getInstance()->getSitesWithAdminAccess();
        $this->assertEquals(array(), $sites);
    }

    /**
     * normal case, admin and view and noaccess website => return only admin
     */
    public function test_getSitesWithAdminAccess_shouldOnlyReturnSitesHavingActuallyAdminAccess()
    {
        API::getInstance()->addSite("site1", array("http://piwik.net", "http://piwik.com/test/"));
        API::getInstance()->addSite("site2", array("http://piwik.com/test/"));
        API::getInstance()->addSite("site3", array("http://piwik.org"));

        $resultWanted = array(
            0 => array("idsite" => 1, "name" => "site1", "main_url" => "http://piwik.net", "ecommerce" => 0, "excluded_ips" => "", 'sitesearch' => 1, 'sitesearch_keyword_parameters' => '', 'sitesearch_category_parameters' => '', 'excluded_parameters' => '', 'excluded_user_agents' => '', 'timezone' => 'UTC', 'currency' => 'USD', 'group' => '', 'keep_url_fragment' => 0, 'type' => 'website', 'exclude_unknown_urls' => 0),
            1 => array("idsite" => 3, "name" => "site3", "main_url" => "http://piwik.org", "ecommerce" => 0, "excluded_ips" => "", 'sitesearch' => 1, 'sitesearch_keyword_parameters' => '', 'sitesearch_category_parameters' => '', 'excluded_parameters' => '', 'excluded_user_agents' => '', 'timezone' => 'UTC', 'currency' => 'USD', 'group' => '', 'keep_url_fragment' => 0, 'type' => 'website', 'exclude_unknown_urls' => 0),
        );

        FakeAccess::setIdSitesAdmin(array(1, 3));

        $sites = API::getInstance()->getSitesWithAdminAccess();

        // we don't test the ts_created
        unset($sites[0]['ts_created']);
        unset($sites[1]['ts_created']);
        $this->assertEquals($resultWanted, $sites);
    }

    public function test_getSitesWithAdminAccess_shouldApplyLimit_IfSet()
    {
        $this->createManySitesWithAdminAccess(40);

        // should return all sites by default
        $sites = API::getInstance()->getSitesWithAdminAccess();
        $this->assertReturnedSitesContainsSiteIds(range(1, 40), $sites);

        // return only 5 sites
        $sites = API::getInstance()->getSitesWithAdminAccess(false, false, 5);
        $this->assertReturnedSitesContainsSiteIds(array(1, 2, 3, 4, 5), $sites);

        // return only 10 sites
        $sites = API::getInstance()->getSitesWithAdminAccess(false, false, 10);
        $this->assertReturnedSitesContainsSiteIds(range(1, 10), $sites);
    }

    public function test_getSitesWithAdminAccess_shouldApplyPattern_IfSetAndFindBySiteName()
    {
        $this->createManySitesWithAdminAccess(40);

        // by site name
        $sites = API::getInstance()->getSitesWithAdminAccess(false, 'site38');
        $this->assertReturnedSitesContainsSiteIds(array(38), $sites);
    }

    public function test_getSitesWithAdminAccess_shouldApplyPattern_IfSetAndFindByUrl()
    {
        $this->createManySitesWithAdminAccess(40);

        $sites = API::getInstance()->getSitesWithAdminAccess(false, 'piwik38.o');
        $this->assertReturnedSitesContainsSiteIds(array(38), $sites);
    }

    public function test_getSitesWithAdminAccess_shouldApplyPattern_AndFindMany()
    {
        $this->createManySitesWithAdminAccess(40);

        $sites = API::getInstance()->getSitesWithAdminAccess(false, '5');
        $this->assertReturnedSitesContainsSiteIds(array(5, 15, 25, 35), $sites);
    }

    public function test_getSitesWithAdminAccess_shouldApplyPatternAndLimit()
    {
        $this->createManySitesWithAdminAccess(40);

        $sites = API::getInstance()->getSitesWithAdminAccess(false, '5', 2);
        $this->assertReturnedSitesContainsSiteIds(array(5, 15), $sites);
    }

    private function createManySitesWithAdminAccess($numSites)
    {
        for ($i = 1; $i <= $numSites; $i++) {
            API::getInstance()->addSite("site" . $i, array("http://piwik$i.org"));
        }

        FakeAccess::setIdSitesAdmin(range(1, $numSites));
    }

    private function assertReturnedSitesContainsSiteIds($expectedSiteIds, $sites)
    {
        $this->assertCount(count($expectedSiteIds), $sites);

        foreach ($sites as $site) {
            $key = array_search($site['idsite'], $expectedSiteIds);
            $this->assertNotFalse($key, 'Did not find expected siteId "' . $site['idsite'] . '" in the expected siteIds');
            unset($expectedSiteIds[$key]);
        }

        $siteIds = var_export($expectedSiteIds, 1);
        $this->assertEmpty($expectedSiteIds, 'Not all expected sites were found, remaining site ids: ' . $siteIds);
    }

    /**
     * there is no admin site available -> array()
     */
    public function test_getSitesWithViewAccess_ReturnsNothing_IfUserHasNoViewOrAdminAccess()
    {
        FakeAccess::setIdSitesView(array());
        FakeAccess::setIdSitesAdmin(array());

        $sites = API::getInstance()->getSitesWithViewAccess();
        $this->assertEquals(array(), $sites);
    }

    /**
     * normal case, admin and view and noaccess website => return only admin
     */
    public function test_getSitesWithViewAccess_ReturnsSitesWithViewAccess()
    {
        API::getInstance()->addSite("site1", array("http://piwik.net", "http://piwik.com/test/"));
        API::getInstance()->addSite("site2", array("http://piwik.com/test/"));
        API::getInstance()->addSite("site3", array("http://piwik.org"));

        $resultWanted = array(
            0 => array("idsite" => 1, "name" => "site1", "main_url" => "http://piwik.net", "ecommerce" => 0, 'sitesearch' => 1, 'sitesearch_keyword_parameters' => '', 'sitesearch_category_parameters' => '', "excluded_ips" => "", 'excluded_parameters' => '', 'excluded_user_agents' => '', 'timezone' => 'UTC', 'currency' => 'USD', 'group' => '', 'keep_url_fragment' => 0, 'type' => 'website', 'exclude_unknown_urls' => 0),
            1 => array("idsite" => 3, "name" => "site3", "main_url" => "http://piwik.org", "ecommerce" => 0, 'sitesearch' => 1, 'sitesearch_keyword_parameters' => '', 'sitesearch_category_parameters' => '', "excluded_ips" => "", 'excluded_parameters' => '', 'excluded_user_agents' => '', 'timezone' => 'UTC', 'currency' => 'USD', 'group' => '', 'keep_url_fragment' => 0, 'type' => 'website', 'exclude_unknown_urls' => 0),
        );

        FakeAccess::setIdSitesView(array(1, 3));
        FakeAccess::setIdSitesAdmin(array());

        $sites = API::getInstance()->getSitesWithViewAccess();

        // we don't test the ts_created

        unset($sites[0]['ts_created']);
        unset($sites[1]['ts_created']);
        $this->assertEquals($resultWanted, $sites);
    }

    /**
     * there is no admin site available -> array()
     */
    public function test_getSitesWithAtLeastViewAccess_ReturnsNothing_WhenUserHasNoAccess()
    {
        FakeAccess::setIdSitesView(array());
        FakeAccess::setIdSitesAdmin(array());

        $sites = API::getInstance()->getSitesWithAtLeastViewAccess();
        $this->assertEquals(array(), $sites);
    }

    /**
     * normal case, admin and view and noaccess website => return only admin
     */
    public function test_getSitesWithAtLeastViewAccess_ReturnsSitesWithViewAccess()
    {
        API::getInstance()->addSite("site1", array("http://piwik.net", "http://piwik.com/test/"), $ecommerce = 1);
        API::getInstance()->addSite("site2", array("http://piwik.com/test/"));
        API::getInstance()->addSite("site3", array("http://piwik.org"));

        $resultWanted = array(
            0 => array("idsite" => 1, "name" => "site1", "main_url" => "http://piwik.net", "ecommerce" => 1, "excluded_ips" => "", 'sitesearch' => 1, 'sitesearch_keyword_parameters' => '', 'sitesearch_category_parameters' => '', 'excluded_parameters' => '', 'excluded_user_agents' => '', 'timezone' => 'UTC', 'currency' => 'USD', 'group' => '', 'keep_url_fragment' => 0, 'type' => 'website', 'exclude_unknown_urls' => 0),
            1 => array("idsite" => 3, "name" => "site3", "main_url" => "http://piwik.org", "ecommerce" => 0, "excluded_ips" => "", 'sitesearch' => 1, 'sitesearch_keyword_parameters' => '', 'sitesearch_category_parameters' => '', 'excluded_parameters' => '', 'excluded_user_agents' => '', 'timezone' => 'UTC', 'currency' => 'USD', 'group' => '', 'keep_url_fragment' => 0, 'type' => 'website', 'exclude_unknown_urls' => 0),
        );

        FakeAccess::setIdSitesView(array(1, 3));
        FakeAccess::setIdSitesAdmin(array());

        $sites = API::getInstance()->getSitesWithAtLeastViewAccess();
        // we don't test the ts_created
        unset($sites[0]['ts_created']);
        unset($sites[1]['ts_created']);
        $this->assertEquals($resultWanted, $sites);
    }

    /**
     * no urls for this site => array()
     */
    public function test_getSiteUrlsFromId_ReturnsMainUrlOnly_WhenNoAliasUrls()
    {
        $idsite = API::getInstance()->addSite("site1", array("http://piwik.net"));

        $urls = API::getInstance()->getSiteUrlsFromId($idsite);
        $this->assertEquals(array("http://piwik.net"), $urls);
    }

    /**
     * normal case
     */
    public function test_getSiteUrlsFromId_ReturnsMainAndAliasUrls()
    {
        $site = array("http://piwik.net",
                      "http://piwik.org",
                      "http://piwik.org",
                      "http://piwik.com");
        sort($site);

        $idsite = API::getInstance()->addSite("site1", $site);

        $siteWanted = array("http://piwik.net",
                            "http://piwik.org",
                            "http://piwik.com");
        sort($siteWanted);
        $urls = API::getInstance()->getSiteUrlsFromId($idsite);

        $this->assertEquals($siteWanted, $urls);
    }

    /**
     * wrongId => exception
     * @expectedException \Exception
     */
    public function test_getSiteUrlsFromId_ThrowsException_WhenSiteIdIsIncorrect()
    {
        FakeAccess::setIdSitesView(array(3));
        FakeAccess::setIdSitesAdmin(array());
        API::getInstance()->getSiteUrlsFromId(1);
    }

    /**
     * one url => no change to alias urls
     */
    public function test_updateSite_WithOneUrl_RemovesAliasUrls_AndUpdatesTheSiteCorrectly()
    {
        $urls = array("http://piwiknew.com",
                      "http://piwiknew.net",
                      "http://piwiknew.org",
                      "http://piwiknew.fr");
        $idsite = API::getInstance()->addSite("site1", $urls);

        $newMainUrl = "http://main.url";

        // Also test that the group was set to empty, and is searchable
        $websites = API::getInstance()->getSitesFromGroup('');
        $this->assertEquals(1, count($websites));

        // the Update doesn't change the group field
        API::getInstance()->updateSite($idsite, "test toto@{}", $newMainUrl);
        $websites = API::getInstance()->getSitesFromGroup('');
        $this->assertEquals(1, count($websites));

        // Updating the group to something
        $group = 'something';
        API::getInstance()->updateSite($idsite, "test toto@{}", $newMainUrl, $ecommerce = 0, $ss = true, $ss_kwd = null, $ss_cat = '', $ips = null, $parametersExclude = null, $timezone = null, $currency = null, $group);

        $websites = API::getInstance()->getSitesFromGroup($group);
        $this->assertEquals(1, count($websites));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($websites[0]['ts_created'])));

        // Updating the group to nothing
        $group = '';
        $type = 'mobileAppTest';
        API::getInstance()->updateSite($idsite, "test toto@{}", $newMainUrl, $ecommerce = 0, $ss = false, $ss_kwd = '', $ss_cat = null, $ips = null, $parametersExclude = null, $timezone = null, $currency = null, $group, $startDate = '2010-01-01', $excludedUserAgent = null, $keepUrlFragment = 1, $type);
        $websites = API::getInstance()->getSitesFromGroup($group);
        $this->assertEquals(1, count($websites));
        $this->assertEquals('2010-01-01', date('Y-m-d', strtotime($websites[0]['ts_created'])));

        // Test setting the website type
        $this->assertEquals($type, Site::getTypeFor($idsite));

        // Check Alias URLs contain only main url
        $allUrls = API::getInstance()->getSiteUrlsFromId($idsite);
        $this->assertEquals($newMainUrl, $allUrls[0]);
        $aliasUrls = array_slice($allUrls, 1);
        $this->assertEquals(array(), $aliasUrls);

    }

    /**
     * strange name and NO URL => name ok, main_url not updated
     */
    public function test_updateSite_WithStrangeName_AndNoAliasUrls_UpdatesTheName_ButNoUrls()
    {
        $idsite = API::getInstance()->addSite("site1", "http://main.url");
        $newName = "test toto@{'786'}";

        API::getInstance()->updateSite($idsite, $newName);

        $site = API::getInstance()->getSiteFromId($idsite);

        $this->assertEquals($newName, $site['name']);
        // url didn't change because parameter url NULL in updateSite
        $this->assertEquals("http://main.url", $site['main_url']);
    }

    /**
     * several urls => both main and alias are updated
     * also test the update of group field
     */
    public function test_updateSite_WithSeveralUrlsAndGroup_UpdatesGroupAndUrls()
    {
        $urls = array("http://piwiknew.com",
                      "http://piwiknew.net",
                      "http://piwiknew.org",
                      "http://piwiknew.fr");

        $group = 'GROUP Before';
        $idsite = API::getInstance()->addSite("site1", $urls, $ecommerce = 1,
            $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null,
            $excludedIps = null, $excludedQueryParameters = null, $timezone = null, $currency = null, $group, $startDate = '2011-01-01');

        $websites = API::getInstance()->getSitesFromGroup($group);
        $this->assertEquals(1, count($websites));

        $newurls = array("http://piwiknew2.com",
                         "http://piwiknew2.net",
                         "http://piwiknew2.org",
                         "http://piwiknew2.fr");

        $groupAfter = '   GROUP After';
        API::getInstance()->updateSite($idsite, "test toto@{}", $newurls, $ecommerce = 0,
            $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null,
            $excludedIps = null, $excludedQueryParameters = null, $timezone = null, $currency = null, $groupAfter);

        // no result for the group before update
        $websites = API::getInstance()->getSitesFromGroup($group);
        $this->assertEquals(0, count($websites));

        // Testing that the group was updated properly (and testing that the group value is trimmed before inserted/searched)
        $websites = API::getInstance()->getSitesFromGroup($groupAfter . ' ');
        $this->assertEquals(1, count($websites));
        $this->assertEquals('2011-01-01', date('Y-m-d', strtotime($websites[0]['ts_created'])));

        // Test fetch website groups
        $expectedGroups = array(trim($groupAfter));
        $fetched = API::getInstance()->getSitesGroups();
        $this->assertEquals($expectedGroups, $fetched);

        $allUrls = API::getInstance()->getSiteUrlsFromId($idsite);
        sort($allUrls);
        sort($newurls);
        $this->assertEquals($newurls, $allUrls);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage SitesManager_OnlyMatchedUrlsAllowed
     */
    public function test_updateSite_ShouldFailAndNotUpdateSite_IfASettingIsInvalid()
    {
        $type  = MobileAppMeasurable\Type::ID;
        $idSite = $this->addSiteWithType($type, array());

        try {
            $settings = array('MobileAppMeasurable' => array(array('name' => 'exclude_unknown_urls', 'value' => 'fooBar')));
            $this->updateSiteSettings($idSite, 'newSiteName', $settings);

        } catch (Exception $e) {
            // verify nothing was updated (not even the name)
            $measurable = new Measurable($idSite);
            $this->assertNotEquals('newSiteName', $measurable->getName());

            throw $e;
        }
    }

    public function test_updateSite_ShouldSavePassedMeasurableSettings_IfSettingsAreValid()
    {
        $type = WebsiteType::ID;
        $idSite = $this->addSiteWithType($type, array());

        $this->assertSame(1, $idSite);

        $settings = array('WebsiteMeasurable' => array(array('name' => 'urls', 'value' => array('http://www.piwik.org'))));

        $this->updateSiteSettings($idSite, 'newSiteName', $settings);

        $settings = $this->getWebsiteMeasurable($idSite);

        // verify it was updated
        $measurable = new Measurable($idSite);
        $this->assertSame('newSiteName', $measurable->getName());
        $this->assertSame(array('http://www.piwik.org'), $settings->urls->getValue());
    }

    public function test_updateSite_CorreclySavesExcludedUnknownUrlSettings()
    {
        $idSite = API::getInstance()->addSite("site1", array("http://piwik.net"));

        $site = API::getInstance()->getSiteFromId($idSite);
        $this->assertEquals(0, $site['exclude_unknown_urls']);

        API::getInstance()->updateSite($idSite, $siteName = null, $urls = null, $ecommerce = null, $siteSearch = null,
            $searchKeywordParams = null, $searchCategoryParams = null, $excludedIps = null, $excludedQueryParameters = null,
            $timzeone = null, $currency = null, $group = null, $startDate = null, $excludedUserAgents = null,
            $keepUrlFragments = null, $type = null, $settings = null, $excludeUnknownUrls = true);

        $site = API::getInstance()->getSiteFromId($idSite);
        $this->assertEquals(1, $site['exclude_unknown_urls']);
    }

    /**
     * @dataProvider getDifferentTypesDataProvider
     */
    public function test_updateSite_WithDifferentTypes($type)
    {
        $idSite = $this->addSiteWithType('website', array());

        $site = API::getInstance()->getSiteFromId($idSite);
        $this->assertEquals(0, $site['exclude_unknown_urls']);

        API::getInstance()->updateSite($idSite, $siteName = 'new site name', $urls = null, $ecommerce = true, $siteSearch = false,
            $searchKeywordParams = null, $searchCategoryParams = null, $excludedIps = null, $excludedQueryParameters = null,
            $timzeone = null, $currency = 'NZD', $group = null, $startDate = null, $excludedUserAgents = null,
            $keepUrlFragments = null, $type, $settings = null, $excludeUnknownUrls = true);

        $site = API::getInstance()->getSiteFromId($idSite);
        $this->assertEquals('new site name', $site['name']);
        $this->assertEquals(1, $site['exclude_unknown_urls']);
        $this->assertEquals(1, $site['ecommerce']);
        $this->assertEquals(0, $site['sitesearch']);
        $this->assertEquals('NZD', $site['currency']);
    }

    public function getDifferentTypesDataProvider()
    {
        return array(
            array('website'),
            array('mobileapp'),
            array('notexistingtype'),
        );
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage SitesManager_ExceptionDeleteSite
     */
    public function test_delete_ShouldNotDeleteASiteInCaseThereIsOnlyOneSite()
    {
        $siteId1 = $this->_addSite();

        $this->assertHasSite($siteId1);

        try {
            API::getInstance()->deleteSite($siteId1);
            $this->fail('an expected exception was not raised');
        } catch (Exception $e) {
            $this->assertHasSite($siteId1);
            throw $e;
        }
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage website id = 99999498 not found
     */
    public function test_delete_ShouldTriggerException_IfGivenSiteDoesNotExist()
    {
        API::getInstance()->deleteSite(99999498);
    }

    public function test_delete_ShouldActuallyRemoveAnExistingSiteButOnlyTheGivenSite()
    {
        $this->_addSite();
        $siteId1 = $this->_addSite();
        $siteId2 = $this->_addSite();

        $this->assertHasSite($siteId1);
        $this->assertHasSite($siteId2);

        API::getInstance()->deleteSite($siteId1);

        $this->assertHasNotSite($siteId1);
        $this->assertHasSite($siteId2);
    }

    public function test_delete_ShouldTriggerAnEventOnceSiteWasActuallyDeleted()
    {
        $called = 0;
        $deletedSiteId = null;

        Piwik::addAction('SitesManager.deleteSite.end', function ($param) use (&$called, &$deletedSiteId) {
            $called++;
            $deletedSiteId = $param;
        });

        $this->_addSite();
        $siteId1 = $this->_addSite();

        API::getInstance()->deleteSite($siteId1);

        $this->assertSame(1, $called);
        $this->assertSame($siteId1, $deletedSiteId);
    }

    private function assertHasSite($idSite)
    {
        $model = new Model();
        $siteInfo = $model->getSiteFromId($idSite);
        $this->assertNotEmpty($siteInfo);
    }

    private function assertHasNotSite($idSite)
    {
        $model = new Model();
        $siteInfo = $model->getSiteFromId($idSite);
        $this->assertEmpty($siteInfo);
    }

    public function testGetSitesGroups()
    {
        $groups = array('group1', ' group1 ', '', 'group2');
        $expectedGroups = array('group1', '', 'group2');
        foreach ($groups as $group) {
            API::getInstance()->addSite("test toto@{}", 'http://example.org', $ecommerce = 1, $siteSearch = null, $searchKeywordParameters = null, $searchCategoryParameters = null, $excludedIps = null, $excludedQueryParameters = null, $timezone = null, $currency = null, $group);
        }

        $this->assertEquals($expectedGroups, API::getInstance()->getSitesGroups());
    }

    public function getInvalidTimezoneData()
    {
        return array(
            array('UTC+15'),
            array('Paris'),
        );
    }

    /**
     *
     * @dataProvider getInvalidTimezoneData
     * @expectedException \Exception
     */
    public function test_addSite_WithInvalidTimezone_ThrowsException($timezone)
    {
        API::getInstance()->addSite("site1", array('http://example.org'), $ecommerce = 0,
            $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, $ip = '', $params = '', $timezone);
    }

    /**
     * @expectedException \Exception
     */
    public function test_addSite_WithInvalidCurrency_ThrowsException()
    {
        $invalidCurrency = '€';
        API::getInstance()->addSite("site1", array('http://example.org'), $ecommerce = 0,
            $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, '', 'UTC', $invalidCurrency);
    }

    public function test_setDefaultTimezone_AndCurrency_AndExcludedQueryParameters_AndExcludedIps_UpdatesDefaultsCorreclty()
    {
        // test that they return default values
        $defaultTimezone = API::getInstance()->getDefaultTimezone();
        $this->assertEquals('UTC', $defaultTimezone);
        $defaultCurrency = API::getInstance()->getDefaultCurrency();
        $this->assertEquals('USD', $defaultCurrency);
        $excludedIps = API::getInstance()->getExcludedIpsGlobal();
        $this->assertEquals('', $excludedIps);
        $excludedQueryParameters = API::getInstance()->getExcludedQueryParametersGlobal();
        $this->assertEquals('', $excludedQueryParameters);

        // test that when not specified, defaults are set as expected
        $idsite = API::getInstance()->addSite("site1", array('http://example.org'));
        $site = new Site($idsite);
        $this->assertEquals('UTC', $site->getTimezone());
        $this->assertEquals('USD', $site->getCurrency());
        $this->assertEquals('', $site->getExcludedQueryParameters());
        $this->assertEquals('', $site->getExcludedIps());
        $this->assertEquals(false, $site->isEcommerceEnabled());

        // set the global timezone and get it
        $newDefaultTimezone = 'UTC+5.5';
        API::getInstance()->setDefaultTimezone($newDefaultTimezone);
        $defaultTimezone = API::getInstance()->getDefaultTimezone();
        $this->assertEquals($newDefaultTimezone, $defaultTimezone);

        // set the default currency and get it
        $newDefaultCurrency = 'EUR';
        API::getInstance()->setDefaultCurrency($newDefaultCurrency);
        $defaultCurrency = API::getInstance()->getDefaultCurrency();
        $this->assertEquals($newDefaultCurrency, $defaultCurrency);

        // set the global IPs to exclude and get it
        $newGlobalExcludedIps = '1.1.1.*,1.1.*.*,150.1.1.1';
        API::getInstance()->setGlobalExcludedIps($newGlobalExcludedIps);
        $globalExcludedIps = API::getInstance()->getExcludedIpsGlobal();
        $this->assertEquals($newGlobalExcludedIps, $globalExcludedIps);

        // set the global URL query params to exclude and get it
        $newGlobalExcludedQueryParameters = 'PHPSESSID,blabla, TesT';
        // removed the space
        $expectedGlobalExcludedQueryParameters = 'PHPSESSID,blabla,TesT';
        API::getInstance()->setGlobalExcludedQueryParameters($newGlobalExcludedQueryParameters);
        $globalExcludedQueryParameters = API::getInstance()->getExcludedQueryParametersGlobal();
        $this->assertEquals($expectedGlobalExcludedQueryParameters, $globalExcludedQueryParameters);

        // create a website and check that default currency and default timezone are set
        // however, excluded IPs and excluded query Params are not returned
        $idsite = API::getInstance()->addSite("site1", array('http://example.org'), $ecommerce = 0,
            $siteSearch = 0, $searchKeywordParameters = 'test1,test2', $searchCategoryParameters = 'test2,test1',
            '', '', $newDefaultTimezone);
        $site = new Site($idsite);
        $this->assertEquals($newDefaultTimezone, $site->getTimezone());
        $this->assertEquals(date('Y-m-d'), $site->getCreationDate()->toString());
        $this->assertEquals($newDefaultCurrency, $site->getCurrency());
        $this->assertEquals('', $site->getExcludedIps());
        $this->assertEquals('', $site->getExcludedQueryParameters());
        $this->assertEquals('test1,test2', $site->getSearchKeywordParameters());
        $this->assertEquals('test2,test1', $site->getSearchCategoryParameters());
        $this->assertFalse($site->isSiteSearchEnabled());
        $this->assertFalse(Site::isSiteSearchEnabledFor($idsite));
        $this->assertFalse($site->isEcommerceEnabled());
        $this->assertFalse(Site::isEcommerceEnabledFor($idsite));
    }

    public function test_getSitesIdFromSiteUrl_AsSuperUser_ReturnsTheRequestedSiteIds()
    {
        API::getInstance()->addSite("site1", array("http://piwik.net", "http://piwik.com"));
        API::getInstance()->addSite("site2", array("http://piwik.com", "http://piwik.net"));
        API::getInstance()->addSite("site3", array("http://piwik.com", "http://piwik.org"));

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://piwik.org');
        $this->assertTrue(count($idsites) == 1);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://www.piwik.org');
        $this->assertTrue(count($idsites) == 1);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://piwik.net');
        $this->assertTrue(count($idsites) == 2);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://piwik.com');
        $this->assertTrue(count($idsites) == 3);
    }

    public function test_getSitesIdFromSiteUrl_MatchesBothHttpAndHttpsUrls_AsSuperUser()
    {
        API::getInstance()->addSite("site1", array("https://piwik.org", "http://example.com", "fb://special-url"));

        $this->assert_getSitesIdFromSiteUrl_matchesBothHttpAndHttpsUrls();
    }

    public function test_getSitesIdFromSiteUrl_MatchesBothHttpAndHttpsUrls_AsUserWithViewPermission()
    {
        API::getInstance()->addSite("site1", array("https://piwik.org", "http://example.com", "fb://special-url"));

        APIUsersManager::getInstance()->addUser("user1", "geqgegagae", "tegst@tesgt.com", "alias");
        APIUsersManager::getInstance()->setUserAccess("user1", "view", array(1));

        // Make sure we're not Super user
        FakeAccess::$superUser = false;
        FakeAccess::$identity = 'user1';
        $this->assertFalse(Piwik::hasUserSuperUserAccess());

        $this->assert_getSitesIdFromSiteUrl_matchesBothHttpAndHttpsUrls();
    }

    private function assert_getSitesIdFromSiteUrl_matchesBothHttpAndHttpsUrls()
    {
        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://piwik.org');
        $this->assertTrue(count($idsites) == 1);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('piwik.org');
        $this->assertTrue(count($idsites) == 1);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('https://www.piwik.org');
        $this->assertTrue(count($idsites) == 1);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('https://example.com');
        $this->assertTrue(count($idsites) == 1);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl("fb://special-url");
        $this->assertTrue(count($idsites) == 1);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('https://random-example.com');
        $this->assertTrue(count($idsites) == 0);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('not-found.piwik.org');
        $this->assertTrue(count($idsites) == 0);

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('piwik.org/not-found/');
        $this->assertTrue(count($idsites) == 0);
    }

    public function test_getSitesIdFromSiteUrl_AsUser()
    {
        API::getInstance()->addSite("site1", array("http://www.piwik.net", "https://piwik.com"));
        API::getInstance()->addSite("site2", array("http://piwik.com", "http://piwik.net"));
        API::getInstance()->addSite("site3", array("http://piwik.com", "http://piwik.org"));

        APIUsersManager::getInstance()->addUser("user1", "geqgegagae", "tegst@tesgt.com", "alias");
        APIUsersManager::getInstance()->setUserAccess("user1", "view", array(1));

        APIUsersManager::getInstance()->addUser("user2", "geqgegagae", "tegst2@tesgt.com", "alias");
        APIUsersManager::getInstance()->setUserAccess("user2", "view", array(1));
        APIUsersManager::getInstance()->setUserAccess("user2", "admin", array(3));

        APIUsersManager::getInstance()->addUser("user3", "geqgegagae", "tegst3@tesgt.com", "alias");
        APIUsersManager::getInstance()->setUserAccess("user3", "view", array(1, 2));
        APIUsersManager::getInstance()->setUserAccess("user3", "admin", array(3));

        FakeAccess::$superUser = false;
        FakeAccess::$identity = 'user1';
        FakeAccess::setIdSitesView(array(1));
        FakeAccess::setIdSitesAdmin(array());

        $this->assertFalse(Piwik::hasUserSuperUserAccess());
        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://piwik.com');
        $this->assertEquals(1, count($idsites));

        // testing URL normalization
        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://www.piwik.com');
        $this->assertEquals(1, count($idsites));
        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://piwik.net');
        $this->assertEquals(1, count($idsites));

        FakeAccess::$superUser = false;
        FakeAccess::$identity = 'user2';
        FakeAccess::setIdSitesView(array(1));
        FakeAccess::setIdSitesAdmin(array(3));

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://piwik.com');
        $this->assertEquals(2, count($idsites));

        FakeAccess::$superUser = false;
        FakeAccess::$identity = 'user3';
        FakeAccess::setIdSitesView(array(1, 2));
        FakeAccess::setIdSitesAdmin(array(3));

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('http://piwik.com');
        $this->assertEquals(3, count($idsites));

        $idsites = API::getInstance()->getSitesIdFromSiteUrl('https://www.piwik.com');
        $this->assertEquals(3, count($idsites));
    }

    public function test_getSitesFromTimezones_ReturnsCorrectIdSites()
    {
        API::getInstance()->addSite("site3", array("http://piwik.org"), null, $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, null, null, 'UTC');
        $idsite2 = API::getInstance()->addSite("site3", array("http://piwik.org"), null, $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, null, null, 'Pacific/Auckland');
        $idsite3 = API::getInstance()->addSite("site3", array("http://piwik.org"), null, $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, null, null, 'Pacific/Auckland');
        $idsite4 = API::getInstance()->addSite("site3", array("http://piwik.org"), null, $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, null, null, 'UTC+10');
        $result = API::getInstance()->getSitesIdFromTimezones(array('UTC+10', 'Pacific/Auckland'));
        $this->assertEquals(array($idsite2, $idsite3, $idsite4), $result);
    }

    public function provideContainerConfig()
    {
        return array(
            'Piwik\Access' => new FakeAccess(),
        );
    }

}
