# Piwik

## Description

Piwik is the leading Free/Libre open analytics platform.

Piwik is a full featured PHP MySQL software program that you download and install on your own webserver.
At the end of the five minute installation process you will be given a JavaScript code.
Simply copy and paste this tag on websites you wish to track and access your analytics reports in real time.

Piwik aims to be a Free software alternative to Google Analytics, and is already used on more than 1,000,000 websites. Privacy is built-in!

## Mission Statement

> « To create, as a community, the leading international Free/Libre web analytics platform, providing access to all functionality through open components and open APIs. »

Or in short:
> « Liberate Web Analytics »

## License

Piwik is released under the GPL v3 (or later) license, see [misc/gpl-3.0.txt](misc/gpl-3.0.txt)


## Requirements

  * PHP 5.5.9 or greater
  * MySQL version 5.5 or greater, or MariaDB 
  * PHP extension pdo and pdo_mysql, or the MySQLi extension.
  * Piwik is OS / server independent

## Install

  * Upload piwik to your webserver
  * Point your browser to the directory
  * Follow the steps
  * Add the given javascript code to your pages
  * (You may also generate fake data to experiment, by enabling the plugin VisitorGenerator)

## Quality Assurance

The Piwik project uses an ever-expanding comprehensive set of thousands of unit tests and hundreds of automated integration tests, system tests, JavaScript tests, and screenshot UI tests, running on a continuous integration server as part of its software quality assurance.

We use [BrowserStack.com](https://www.browserstack.com/) testing tool to help check the Piwik user interface is compatible with many browsers.

## Security

Security is a top priority at Piwik. As potential issues are discovered, we validate, patch and release fixes as quickly as we can. We have a security bug bounty program in place that rewards researchers for finding security issues and disclosing them to us. 
