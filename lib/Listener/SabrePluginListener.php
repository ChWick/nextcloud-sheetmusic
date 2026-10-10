<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Listener;

use OCA\SheetMusic\Dav\SheetMusicDavPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\SabrePluginEvent;

/**
 * @template-implements IEventListener<SabrePluginEvent>
 */
class SabrePluginListener implements IEventListener {
	public function __construct(
		private SheetMusicDavPlugin $davPlugin,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginEvent) {
			return;
		}

		$server = $event->getServer();
		if ($server !== null) {
			$server->addPlugin($this->davPlugin);
		}
	}
}
