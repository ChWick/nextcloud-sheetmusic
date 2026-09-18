<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\MobileSheetsManager\AppInfo\Application::APP_ID, OCA\MobileSheetsManager\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\MobileSheetsManager\AppInfo\Application::APP_ID, OCA\MobileSheetsManager\AppInfo\Application::APP_ID . '-main');

?>

<div id="mobilesheetsmanager"></div>
