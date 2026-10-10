<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Listener;

use OCA\SheetMusic\Dav\SheetMusicDavPlugin;
use OCA\SheetMusic\Listener\SabrePluginListener;
use OCP\EventDispatcher\Event;
use OCP\SabrePluginEvent;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Server;

final class SabrePluginListenerTest extends TestCase {
	public function testHandleAddsDavPluginToServer(): void {
		$davPlugin = $this->createMock(SheetMusicDavPlugin::class);
		$listener = new SabrePluginListener($davPlugin);

		$server = $this->createMock(Server::class);
		$server->expects(self::once())
			->method('addPlugin')
			->with($davPlugin);

		$event = new SabrePluginEvent($server);
		$listener->handle($event);
	}

	public function testHandleIgnoresOtherEvents(): void {
		$davPlugin = $this->createMock(SheetMusicDavPlugin::class);
		$listener = new SabrePluginListener($davPlugin);

		$otherEvent = $this->createMock(Event::class);
		$listener->handle($otherEvent);
		self::assertTrue(true);
	}
}
