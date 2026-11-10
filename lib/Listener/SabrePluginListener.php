<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\SheetMusic\Dav\SheetMusicDavPlugin;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds the virtual MobileSheets files plugin to the WebDAV servers.
 *
 * {@see SabrePluginAddEvent} is dispatched as a typed event for both
 * remote.php/dav and the legacy remote.php/webdav endpoint.
 * {@see BeforeSabrePubliclyLoadedEvent} is dispatched for public WebDAV
 * endpoints (public.php/dav and public.php/webdav).
 *
 * @template-implements IEventListener<Event>
 */
class SabrePluginListener implements IEventListener {
	public function __construct(
		private SheetMusicDavPlugin $davPlugin,
	) {
	}

	public function handle(Event $event): void {
		/** @psalm-suppress UndefinedClass OCA\DAV is part of the server, not of the OCP package */
		if (!$event instanceof SabrePluginAddEvent && !$event instanceof BeforeSabrePubliclyLoadedEvent) {
			return;
		}

		/** @psalm-suppress UndefinedClass */
		$server = $event->getServer();
		if ($server->getPlugin($this->davPlugin->getPluginName()) === null) {
			$server->addPlugin($this->davPlugin);
		}
	}
}
