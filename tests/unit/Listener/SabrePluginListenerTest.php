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
use Psr\Log\NullLogger;
use Sabre\DAV\Server;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;

final class SabrePluginListenerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private SheetMusicDavPlugin $davPlugin;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);

		$this->davPlugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$this->exporter,
			$this->importer,
			new NullLogger(),
			new FakeUserSession($user),
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testHandleAddsDavPluginToServer(): void {
		$listener = new SabrePluginListener($this->davPlugin);
		$server = new Server();

		$listener->handle(new SabrePluginAddEvent($server));

		self::assertSame($this->davPlugin, $server->getPlugin('sheetmusic-virtual-files'));
	}

	public function testHandleDoesNotAddPluginTwice(): void {
		$listener = new SabrePluginListener($this->davPlugin);
		$server = new Server();
		$server->addPlugin($this->davPlugin);

		$listener->handle(new SabrePluginAddEvent($server));

		self::assertSame($this->davPlugin, $server->getPlugin('sheetmusic-virtual-files'));
	}

	public function testHandleIgnoresOtherEvents(): void {
		$listener = new SabrePluginListener($this->davPlugin);
		$server = new Server();

		$listener->handle(new SabrePluginEvent($server));
		$listener->handle(new class extends Event {});

		self::assertNull($server->getPlugin('sheetmusic-virtual-files'));
	}
}
