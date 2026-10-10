<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\SheetMusic\Dav\SheetMusicDavPlugin;
use OCA\SheetMusic\Listener\SabrePluginListener;
use OCP\EventDispatcher\Event;
use OCP\SabrePluginEvent;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Server;

final class SabrePluginListenerTest extends TestCase {
	public function testHandleAddsDavPluginToServer(): void {
		$davPlugin = $this->createMock(SheetMusicDavPlugin::class);
		$davPlugin->method('getPluginName')->willReturn('sheetmusic-virtual-files');
		$listener = new SabrePluginListener($davPlugin);

		$server = $this->createMock(Server::class);
		$server->method('getPlugin')->willReturn(null);
		$server->expects(self::once())
			->method('addPlugin')
			->with($davPlugin);

		$listener->handle(new SabrePluginAddEvent($server));
	}

	public function testHandleDoesNotAddPluginTwice(): void {
		$davPlugin = $this->createMock(SheetMusicDavPlugin::class);
		$davPlugin->method('getPluginName')->willReturn('sheetmusic-virtual-files');
		$listener = new SabrePluginListener($davPlugin);

		$server = $this->createMock(Server::class);
		$server->method('getPlugin')->willReturn($davPlugin);
		$server->expects(self::never())->method('addPlugin');

		$listener->handle(new SabrePluginAddEvent($server));
	}

	public function testHandleIgnoresOtherEvents(): void {
		$davPlugin = $this->createMock(SheetMusicDavPlugin::class);
		$listener = new SabrePluginListener($davPlugin);

		$server = $this->createMock(Server::class);
		$server->expects(self::never())->method('addPlugin');

		$listener->handle(new SabrePluginEvent($server));
		$listener->handle($this->createMock(Event::class));
	}
}
