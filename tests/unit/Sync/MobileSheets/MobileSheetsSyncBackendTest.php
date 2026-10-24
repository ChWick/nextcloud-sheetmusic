<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSyncBackend;
use OCA\SheetMusic\Sync\SyncClientRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

final class MobileSheetsSyncBackendTest extends TestCase {
	private MobileSheetsSqliteExporter $exporter;
	private MobileSheetsSqliteImporter $importer;
	private LibraryMapper $libraryMapper;
	private MobileSheetsSyncBackend $backend;
	private SyncClientRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->exporter = $this->createMock(MobileSheetsSqliteExporter::class);
		$this->importer = $this->createMock(MobileSheetsSqliteImporter::class);
		$this->libraryMapper = $this->createMock(LibraryMapper::class);

		$this->backend = new MobileSheetsSyncBackend(
			$this->exporter,
			$this->importer,
			$this->libraryMapper,
			new NullLogger(),
		);

		$this->registry = new SyncClientRegistry([$this->backend]);
	}

	public function testClientTypeAndVirtualFiles(): void {
		self::assertSame('mobilesheets', $this->backend->getClientType());
		self::assertSame(['mobilesheets.db', 'mobilesheets_hashcodes.txt'], $this->backend->getVirtualFileNames());
		self::assertTrue($this->backend->handlesVirtualFile('mobilesheets.db'));
		self::assertTrue($this->backend->handlesVirtualFile('mobilesheets_hashcodes.txt'));
		self::assertFalse($this->backend->handlesVirtualFile('other.db'));
	}

	public function testRegistryLookup(): void {
		self::assertSame($this->backend, $this->registry->getClient('mobilesheets'));
		self::assertNull($this->registry->getClient('unknown'));

		self::assertSame($this->backend, $this->registry->getClientForVirtualFile('mobilesheets.db'));
		self::assertSame($this->backend, $this->registry->getClientForVirtualFile('mobilesheets_hashcodes.txt'));
		self::assertNull($this->registry->getClientForVirtualFile('unknown.file'));

		self::assertContains('mobilesheets.db', $this->registry->getAllVirtualFileNames());
		self::assertContains('mobilesheets_hashcodes.txt', $this->registry->getAllVirtualFileNames());
		self::assertCount(1, $this->registry->getClients());
	}

	public function testExportAndImportDelegation(): void {
		$this->exporter->expects(self::once())
			->method('exportToFile')
			->with(42, '/tmp/target.db', 'admin');

		$this->backend->exportLibrary(42, '/tmp/target.db', 'admin');

		$this->importer->expects(self::once())
			->method('importFromFile')
			->with(42, '/tmp/source.db', 'admin');

		$this->backend->importLibrary(42, '/tmp/source.db', 'admin');
	}

	public function testVirtualFileProperties(): void {
		$library = new InternalLibrary(id: 42, userId: 'admin', path: '/Noten', updatedAt: 1234567890);

		$this->exporter->method('generateHashcodes')
			->with($library)
			->willReturn("file.pdf\n123\n1000\n500");

		$hashProps = $this->backend->getVirtualFileProperties($library, 'mobilesheets_hashcodes.txt', 'admin');
		self::assertNotNull($hashProps);
		self::assertSame('text/plain; charset=utf-8', $hashProps['contentType']);
		self::assertSame(1234567890, $hashProps['lastModified']);
		self::assertSame(strlen("file.pdf\n123\n1000\n500"), $hashProps['size']);
		self::assertSame('"sm-hash-42-1234567890"', $hashProps['etag']);

		$this->exporter->method('exportToFile')->willReturnCallback(
			function ($lib, string $target): void {
				file_put_contents($target, 'SQLite format 3' . "\0" . str_repeat('x', 84));
			},
		);

		$dbProps = $this->backend->getVirtualFileProperties($library, 'mobilesheets.db', 'admin');
		self::assertNotNull($dbProps);
		self::assertSame('application/x-sqlite3', $dbProps['contentType']);
		self::assertSame(1234567890, $dbProps['lastModified']);
		self::assertSame(100, $dbProps['size']);
		self::assertSame('"sm-db-42-1234567890"', $dbProps['etag']);

		self::assertNull($this->backend->getVirtualFileProperties($library, 'unknown.txt', 'admin'));
	}

	public function testHandleGetDatabase(): void {
		$library = new InternalLibrary(id: 42, userId: 'admin', path: '/Noten', updatedAt: 1234567890);
		$this->exporter->method('exportToFile')->willReturnCallback(
			function ($lib, string $target): void {
				file_put_contents($target, 'SQLite format 3' . "\0" . str_repeat('x', 84));
			},
		);

		$request = new Request('GET', '/remote.php/dav/files/admin/Noten/mobilesheets.db');
		$response = new Response();

		$handled = $this->backend->handleGet($request, $response, $library, 'mobilesheets.db', false);
		self::assertFalse($handled);
		self::assertSame(200, $response->getStatus());
		self::assertSame('application/x-sqlite3', $response->getHeader('Content-Type'));
		self::assertSame('"sm-db-42-1234567890"', $response->getHeader('ETag'));
		self::assertSame(100, (int)$response->getHeader('Content-Length'));

		// Test 304 Not Modified when ETag matches
		$ifNoneMatchRequest = new Request('GET', '/remote.php/dav/files/admin/Noten/mobilesheets.db', [
			'If-None-Match' => '"sm-db-42-1234567890"',
		]);
		$notModifiedResponse = new Response();
		$handled304 = $this->backend->handleGet($ifNoneMatchRequest, $notModifiedResponse, $library, 'mobilesheets.db', false);
		self::assertFalse($handled304);
		self::assertSame(304, $notModifiedResponse->getStatus());
	}

	public function testHandlePutDatabase(): void {
		$library = new InternalLibrary(id: 42, userId: 'admin', path: '/Noten', updatedAt: 1234567890);
		$updatedLibrary = new InternalLibrary(id: 42, userId: 'admin', path: '/Noten', updatedAt: 1234567999);

		$this->importer->expects(self::once())
			->method('importFromFile')
			->with($library, self::isType('string'));

		$this->libraryMapper->expects(self::once())
			->method('touch')
			->with(42);

		$this->libraryMapper->expects(self::once())
			->method('findModelById')
			->with(42)
			->willReturn($updatedLibrary);

		$request = new Request('PUT', '/remote.php/dav/files/admin/Noten/mobilesheets.db', [], 'dummy db content');
		$response = new Response();

		$handled = $this->backend->handlePut($request, $response, $library, 'mobilesheets.db');
		self::assertFalse($handled);
		self::assertSame(204, $response->getStatus());
		self::assertSame('"sm-db-42-1234567999"', $response->getHeader('ETag'));
	}

	public function testHandleGetHashcodes(): void {
		$library = new InternalLibrary(id: 42, userId: 'admin', path: '/Noten', updatedAt: 1234567890);
		$this->exporter->method('generateHashcodes')
			->with($library)
			->willReturn("file.pdf\n123\n1000\n500");

		$request = new Request('GET', '/remote.php/dav/files/admin/Noten/mobilesheets_hashcodes.txt');
		$response = new Response();

		$handled = $this->backend->handleGet($request, $response, $library, 'mobilesheets_hashcodes.txt', false);
		self::assertFalse($handled); // In SabreDAV convention, false stops propagation because request was handled
		self::assertSame(200, $response->getStatus());
		self::assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
		self::assertSame("file.pdf\n123\n1000\n500", $response->getBodyAsString());
	}

	public function testHandlePutHashcodesAcknowledged(): void {
		$library = new InternalLibrary(id: 42, userId: 'admin', path: '/Noten', updatedAt: 1234567890);
		$request = new Request('PUT', '/remote.php/dav/files/admin/Noten/mobilesheets_hashcodes.txt');
		$response = new Response();

		$handled = $this->backend->handlePut($request, $response, $library, 'mobilesheets_hashcodes.txt');
		self::assertFalse($handled);
		self::assertSame(204, $response->getStatus());
	}
}
