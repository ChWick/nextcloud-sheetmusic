<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Dav;

use OCA\SheetMusic\Dav\SyncClientDetector;
use PHPUnit\Framework\TestCase;
use Sabre\HTTP\Request;

final class SyncClientDetectorTest extends TestCase {
	private SyncClientDetector $detector;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->detector = new SyncClientDetector();
	}

	public function testDetectsDesktopSyncClients(): void {
		$windowsUa = 'Mozilla/5.0 (Windows) mirall/3.14.0 (Nextcloud, windows-10.0.19045 ClientArchitecture: x86_64 OSArchitecture: x86_64)';
		self::assertTrue($this->detector->isSyncClientUserAgent($windowsUa));
		self::assertTrue($this->detector->isSyncClient(new Request('PROPFIND', '/', ['User-Agent' => $windowsUa])));

		$linuxUa = 'Mozilla/5.0 (Linux) mirall/3.12.1 (Nextcloud, Ubuntu 24.04)';
		self::assertTrue($this->detector->isSyncClientUserAgent($linuxUa));

		$macUa = 'Mozilla/5.0 (Macintosh) mirall/3.13.0 (Nextcloud, macOS 14.5)';
		self::assertTrue($this->detector->isSyncClientUserAgent($macUa));

		$csyncUa = 'Mozilla/5.0 (Linux) csyncoC/1.0';
		self::assertTrue($this->detector->isSyncClientUserAgent($csyncUa));

		$ownCloudUa = 'Mozilla/5.0 (Windows) mirall/2.11.0 (ownCloud, windows-10.0.19045)';
		self::assertTrue($this->detector->isSyncClientUserAgent($ownCloudUa));
	}

	public function testDetectsMobileSyncClients(): void {
		$androidUa = 'Mozilla/5.0 (Android) Nextcloud-android/3.30.0';
		self::assertTrue($this->detector->isSyncClientUserAgent($androidUa));
		self::assertTrue($this->detector->isSyncClient(new Request('GET', '/', ['User-Agent' => $androidUa])));

		$iosUa = 'Mozilla/5.0 (iOS) Nextcloud-iOS/5.4.0';
		self::assertTrue($this->detector->isSyncClientUserAgent($iosUa));
		self::assertTrue($this->detector->isSyncClient(new Request('GET', '/', ['User-Agent' => $iosUa])));

		$ownCloudAndroidUa = 'Mozilla/5.0 (Android) ownCloud-android/4.0.0';
		self::assertTrue($this->detector->isSyncClientUserAgent($ownCloudAndroidUa));

		$ownCloudIosUa = 'Mozilla/5.0 (iOS) ownCloud-iOS/4.0.0';
		self::assertTrue($this->detector->isSyncClientUserAgent($ownCloudIosUa));
	}

	public function testAllowsNonSyncClients(): void {
		$mobileSheetsUa = 'MobileSheets/3.8.4';
		self::assertFalse($this->detector->isSyncClientUserAgent($mobileSheetsUa));
		self::assertFalse($this->detector->isSyncClient(new Request('PROPFIND', '/', ['User-Agent' => $mobileSheetsUa])));

		$okhttpUa = 'okhttp/4.9.2';
		self::assertFalse($this->detector->isSyncClientUserAgent($okhttpUa));

		$chromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
		self::assertFalse($this->detector->isSyncClientUserAgent($chromeUa));

		$firefoxUa = 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0';
		self::assertFalse($this->detector->isSyncClientUserAgent($firefoxUa));

		$curlUa = 'curl/8.5.0';
		self::assertFalse($this->detector->isSyncClientUserAgent($curlUa));

		$cyberduckUa = 'Cyberduck/8.6.0';
		self::assertFalse($this->detector->isSyncClientUserAgent($cyberduckUa));
	}

	public function testHandlesEmptyAndMissingUserAgent(): void {
		self::assertFalse($this->detector->isSyncClientUserAgent(''));
		self::assertFalse($this->detector->isSyncClientUserAgent('   '));
		self::assertFalse($this->detector->isSyncClient(new Request('PROPFIND', '/')));
	}
}
