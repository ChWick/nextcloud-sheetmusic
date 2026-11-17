<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\SheetMusic\AppInfo\Application::APP_ID, OCA\SheetMusic\AppInfo\Application::APP_ID . '-pwa');
Util::addScript(OCA\SheetMusic\AppInfo\Application::APP_ID, OCA\SheetMusic\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\SheetMusic\AppInfo\Application::APP_ID, OCA\SheetMusic\AppInfo\Application::APP_ID . '-main');

?>

<style>
@media (display-mode: standalone),
	(display-mode: fullscreen),
	(display-mode: minimal-ui),
	(display-mode: window-controls-overlay) {
	:root,
	html,
	body,
	#body-user {
		--header-height: 0px !important;
		--body-container-margin: 0px !important;
		--body-container-radius: 0px !important;
		--body-height: 100dvh !important;
	}

	html,
	body {
		width: 100% !important;
		height: 100dvh !important;
		margin: 0 !important;
		padding: 0 !important;
		overflow: hidden !important;
	}

	#header {
		display: none !important;
	}

	#content.app-sheetmusic,
	#sheetmusic,
	#content.app-sheetmusic .content {
		margin: 0 !important;
		padding: 0 !important;
		top: 0 !important;
		left: 0 !important;
		right: 0 !important;
		bottom: 0 !important;
		width: 100% !important;
		height: 100dvh !important;
		max-width: 100vw !important;
		max-height: 100dvh !important;
		border-radius: 0 !important;
	}

	#content.app-sheetmusic .content {
		padding: env(safe-area-inset-top, 0px) env(safe-area-inset-right, 0px) env(safe-area-inset-bottom, 0px) env(safe-area-inset-left, 0px) !important;
	}
}
</style>

<div id="sheetmusic"></div>
