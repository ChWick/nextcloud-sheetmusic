<?php

declare(strict_types=1);

// The tests need no other app of the server. Loading them all boots apps that
// do not survive the CLI bootstrap - admin_audit fails to resolve its audit
// logger there and logs "Could not boot admin_audit" on every run - so the
// switch the server bootstrap understands is set before it is pulled in.
putenv('TEST_DONT_LOAD_APPS=1');

require_once __DIR__ . '/../../../tests/bootstrap.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
	require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/TestSupport/MobileSheetsTestDatabase.php';
require_once __DIR__ . '/TestSupport/TestUtils.php';
require_once __DIR__ . '/TestSupport/RealMobileSheetsTestTrait.php';
require_once __DIR__ . '/TestSupport/MobileSheetsDatabasePresets.php';
require_once __DIR__ . '/TestSupport/MobileSheetsEndToEndSyncHarness.php';
require_once __DIR__ . '/TestSupport/InternalQueryResult.php';
require_once __DIR__ . '/TestSupport/FakeUserSession.php';
require_once __DIR__ . '/TestSupport/FakeRequest.php';

\OC_App::loadApp(OCA\SheetMusic\AppInfo\Application::APP_ID);
OC_Hook::clear();
