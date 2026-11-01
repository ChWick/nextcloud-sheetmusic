<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSyncBackend;
use OCA\SheetMusic\Sync\SyncClientRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsSyncBackendTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private MobileSheetsSyncBackend $backend;
	private SyncClientRegistry $registry;
	private InternalLibrary $library;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();

		$this->library = $this->createTestLibrary('/SyncNoten', 'admin');
		$this->dbConn->executeStatement('UPDATE *PREFIX*sheetmusic_libraries SET updated_at = 2000 WHERE id = ?', [$this->library->id]);
		$lib = $this->libraryMapper->findModelById((int)$this->library->id);
		self::assertNotNull($lib);
		$this->library = $lib;

		// Add a song with a PDF file so the library is populated with real data
		$song = new InternalSong(
			id: null,
			uuid: 'backend-song-uuid-1',
			libraryId: (int)$this->library->id,
			title: 'Sync Song',
			clientId: 1,
			createdAt: 2000,
			updatedAt: 2000,
		);
		$createdSong = $this->songMapper->insert($song);
		$this->attachTestFileToSong($createdSong, 'sync_score.pdf', 1);

		$this->backend = new MobileSheetsSyncBackend(
			$this->exporter,
			$this->importer,
			$this->libraryMapper,
			new NullLogger(),
		);

		$this->registry = new SyncClientRegistry([$this->backend]);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
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
		$targetPath = $this->tempDbPath();
		$this->backend->exportLibrary((int)$this->library->id, $targetPath, 'admin');

		self::assertFileExists($targetPath);
		$content = (string)file_get_contents($targetPath);
		self::assertStringStartsWith("SQLite format 3\0", $content);

		// Import the exported file back into the library
		$this->backend->importLibrary((int)$this->library->id, $targetPath, 'admin');
		$songs = $this->songMapper->findAllForLibrary((int)$this->library->id);
		self::assertCount(1, $songs);
	}

	public function testVirtualFileProperties(): void {
		$hashProps = $this->backend->getVirtualFileProperties($this->library, 'mobilesheets_hashcodes.txt', 'admin');
		self::assertNotNull($hashProps);
		self::assertSame('text/plain; charset=utf-8', $hashProps['contentType']);
		self::assertSame($this->library->updatedAt, $hashProps['lastModified']);
		self::assertGreaterThan(0, $hashProps['size']);
		self::assertSame('"sm-hash-' . $this->library->id . '-2000"', $hashProps['etag']);

		$dbProps = $this->backend->getVirtualFileProperties($this->library, 'mobilesheets.db', 'admin');
		self::assertNotNull($dbProps);
		self::assertSame('application/x-sqlite3', $dbProps['contentType']);
		self::assertSame($this->library->updatedAt, $dbProps['lastModified']);
		self::assertGreaterThan(0, $dbProps['size']);
		self::assertSame('"sm-db-' . $this->library->id . '-2000"', $dbProps['etag']);

		self::assertNull($this->backend->getVirtualFileProperties($this->library, 'unknown.txt', 'admin'));
	}

	public function testVirtualFilePropertiesReturnsNullWhenLibraryIsEmpty(): void {
		$emptyLib = $this->createTestLibrary('/EmptyLibForSync', 'admin');

		self::assertNull($this->backend->getVirtualFileProperties($emptyLib, 'mobilesheets.db', 'admin'));
		self::assertNull($this->backend->getVirtualFileProperties($emptyLib, 'mobilesheets_hashcodes.txt', 'admin'));
	}

	public function testDatabaseSizeReturnsZeroWhenExportThrows(): void {
		$invalidLib = new InternalLibrary(id: null, userId: 'admin', path: '/NoId', updatedAt: time());

		$size = $this->backend->databaseSize($invalidLib);
		self::assertSame(0, $size, 'databaseSize must return 0 when library has no ID');
	}

	public function testHandleGetDatabase(): void {
		$request = new Request('GET', '/remote.php/dav/files/admin/SyncNoten/mobilesheets.db');
		$response = new Response();

		$handled = $this->backend->handleGet($request, $response, $this->library, 'mobilesheets.db', false);
		self::assertFalse($handled);
		self::assertSame(200, $response->getStatus());
		self::assertSame('application/x-sqlite3', $response->getHeader('Content-Type'));
		self::assertSame('"sm-db-' . $this->library->id . '-2000"', $response->getHeader('ETag'));
		self::assertGreaterThan(0, (int)$response->getHeader('Content-Length'));

		// Test 304 Not Modified when ETag matches
		$ifNoneMatchRequest = new Request('GET', '/remote.php/dav/files/admin/SyncNoten/mobilesheets.db', [
			'If-None-Match' => '"sm-db-' . $this->library->id . '-2000"',
		]);
		$notModifiedResponse = new Response();
		$handled304 = $this->backend->handleGet($ifNoneMatchRequest, $notModifiedResponse, $this->library, 'mobilesheets.db', false);
		self::assertFalse($handled304);
		self::assertSame(304, $notModifiedResponse->getStatus());
	}

	public function testHandlePutDatabase(): void {
		$targetPath = $this->tempDbPath();
		$this->backend->exportLibrary((int)$this->library->id, $targetPath, 'admin');
		$dbBytes = (string)file_get_contents($targetPath);

		$request = new Request('PUT', '/remote.php/dav/files/admin/SyncNoten/mobilesheets.db', [], $dbBytes);
		$response = new Response();

		$handled = $this->backend->handlePut($request, $response, $this->library, 'mobilesheets.db');
		self::assertFalse($handled);
		self::assertSame(204, $response->getStatus());
		$etag = $response->getHeader('ETag');
		self::assertNotNull($etag);
		self::assertStringStartsWith('"sm-db-' . $this->library->id . '-', $etag);
	}

	public function testHandleGetHashcodes(): void {
		$request = new Request('GET', '/remote.php/dav/files/admin/SyncNoten/mobilesheets_hashcodes.txt');
		$response = new Response();

		$handled = $this->backend->handleGet($request, $response, $this->library, 'mobilesheets_hashcodes.txt', false);
		self::assertFalse($handled);
		self::assertSame(200, $response->getStatus());
		self::assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
		self::assertStringContainsString('sync_score.pdf', $response->getBodyAsString());
	}

	public function testHandlePutHashcodesAcknowledged(): void {
		$request = new Request('PUT', '/remote.php/dav/files/admin/SyncNoten/mobilesheets_hashcodes.txt');
		$response = new Response();

		$handled = $this->backend->handlePut($request, $response, $this->library, 'mobilesheets_hashcodes.txt');
		self::assertFalse($handled);
		self::assertSame(204, $response->getStatus());
	}
}
