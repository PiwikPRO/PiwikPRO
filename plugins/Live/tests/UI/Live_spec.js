/*!
 * Piwik - free/libre analytics platform
 *
 * Screenshot integration tests.
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

describe("Live", function () {
    this.timeout(0);

    this.fixture = "Piwik\\Plugins\\Live\\tests\\Fixtures\\VisitsWithAllActionsAndDevices";

    it('should show visitor log', function (done) {
        expect.screenshot('visitor_log').to.be.captureSelector('.reporting-page', function (page) {
            page.load("?module=CoreHome&action=index&idSite=1&period=year&date=2010-01-03#?idSite=1&period=year&date=2010-01-03&category=General_Visitors&subcategory=Live_VisitorLog");
            page.wait(4000);
        }, done);
    });

    it('should show visitor profile', function (done) {
        expect.screenshot('visitor_profile').to.be.captureSelector('.ui-dialog', function (page) {
            page.evaluate(function(){
                $('.card:first-child .visitor-log-visitor-profile-link').click();
            });
            page.wait(6000);
        }, done);
    });

    it('should hide all action details', function (done) {
        expect.screenshot('visitor_profile_actions_hidden').to.be.captureSelector('.ui-dialog', function (page) {
            page.evaluate(function(){
                $('.visitor-profile-toggle-actions').click();
            }, 500);
        }, done);
    });

    it('should show visit details', function (done) {
        expect.screenshot('visitor_profile_visit_details').to.be.captureSelector('.ui-dialog', function (page) {
            page.evaluate(function(){
                $('.visitor-profile-visit-title')[0].click();
            }, 200);
        }, done);
    });

    it('should show action details', function (done) {
        expect.screenshot('visitor_profile_action_details').to.be.captureSelector('.ui-dialog', function (page) {
            page.click('.visitor-profile-visits li:first-child .visitor-profile-show-actions', 200);
        }, done);
    });

    it('should show action tooltip', function (done) {
        expect.screenshot('visitor_profile_action_tooltip').to.be.captureSelector('.ui-tooltip:visible', function (page) {
            page.mouseMove('.visitor-profile-visits li:first-child .visitor-profile-actions .action:first-child', 200);
        }, done);
    });

    it('should show visitor log purge message when purged and no data', function (done) {
        expect.screenshot('visitor_log_purged').to.be.captureSelector('.reporting-page', function (page) {

            // Enable development mode to be able to see the UI demo page
            testEnvironment.overrideConfig('Deletelogs', 'delete_logs_enable', 1);
            testEnvironment.overrideConfig('Deletelogs', 'delete_logs_older_than', 4000);
            testEnvironment.save();

            page.load("?module=CoreHome&action=index&idSite=1&period=year&date=2005-01-03#?idSite=1&period=year&date=2005-01-03&category=General_Visitors&subcategory=Live_VisitorLog");
            page.wait(4000);
        }, done);
    });
});
