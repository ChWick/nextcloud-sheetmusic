<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\SheetMusic\AppInfo\Application::APP_ID, OCA\SheetMusic\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\SheetMusic\AppInfo\Application::APP_ID, OCA\SheetMusic\AppInfo\Application::APP_ID . '-main');

?>

<div id="sheetmusic"></div>
