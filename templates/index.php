<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\SheetMusic\AppInfo\Application::APP_ID, OCA\SheetMusic\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\SheetMusic\AppInfo\Application::APP_ID, OCA\SheetMusic\AppInfo\Application::APP_ID . '-main');

?>

<style>
@media (display-mode: standalone),
	(display-mode: fullscreen),
	(display-mode: minimal-ui),
	(display-mode: window-controls-overlay) {
	:root {
		--header-height: 0px !important;
		--body-container-margin: 0px !important;
		--body-container-radius: 0px !important;
		--body-height: 100dvh !important;
	}

	#header {
		display: none !important;
	}

	#content.app-sheetmusic {
		margin: 0 !important;
		top: 0 !important;
		left: 0 !important;
		width: 100% !important;
		height: 100dvh !important;
		max-height: 100dvh !important;
		border-radius: 0 !important;
		padding-top: env(safe-area-inset-top, 0px) !important;
		padding-left: env(safe-area-inset-left, 0px) !important;
		padding-right: env(safe-area-inset-right, 0px) !important;
	}
}
</style>

<div id="sheetmusic"></div>
