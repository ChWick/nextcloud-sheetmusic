<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Tests\Unit\Service;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\IntegrityIssue;
use OCA\SheetMusic\Service\LibraryIntegrityFixer;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryOrphanScanner;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\LibraryToolService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryToolServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LibraryToolService $toolService;
	private LibraryIntegrityFixer $fixer;
	private LibraryIntegrityService $integrityService;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$logger = new NullLogger();
		$libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);
		$orphanScanner = new LibraryOrphanScanner(
			$fileManager,
			$this->rootFolder,
			$this->pathResolver,
		);
		$this->toolService = new LibraryToolService(
			$this->songMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$fileManager,
			$this->rootFolder,
			$this->pathResolver,
			$libraryService,
			$logger,
		);
		$this->fixer = new LibraryIntegrityFixer(
			$libraryService,
			$fileManager,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$this->entityMapper,
			$this->songMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$orphanScanner,
		);
		$this->integrityService = new LibraryIntegrityService(
			$libraryService,
			$fileManager,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$this->entityMapper,
			$this->songMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$orphanScanner,
			$this->fixer,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	private function setupLibraryFolder(InternalLibrary $lib): void {
		$userFolder = $this->rootFolder->getUserFolder($lib->userId);
		$libPath = trim($lib->path, '/');
		if (!$userFolder->nodeExists($libPath)) {
			$folder = $userFolder->newFolder($libPath);
			$this->createdUserNodes[] = $folder;
		}
	}

	public function testAuditPageOrdersDetectsStuckAndNormalFiles(): void {
		$lib = $this->createTestLibrary();
		$this->setupLibraryFolder($lib);
		$user = $this->rootFolder->getUserFolder($lib->userId)->getOwner();

		$song1 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'song-uuid-1',
			libraryId: $lib->id,
			title: 'Multi-page stuck song',
		));

		$file1 = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'file-uuid-1',
			songId: $song1->id,
			fileId: 101,
			pageOrder: '1-1',
			pageCount: 4,
			fileSize: 2048,
			type: InternalSongFile::FILE_TYPE_PDF,
			createdAt: time(),
			updatedAt: time(),
		));

		$song2 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'song-uuid-2',
			libraryId: $lib->id,
			title: 'Normal multi-page song',
		));

		$this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'file-uuid-2',
			songId: $song2->id,
			fileId: 102,
			pageOrder: '1-3',
			pageCount: 3,
			fileSize: 1024,
			type: InternalSongFile::FILE_TYPE_PDF,
			createdAt: time(),
			updatedAt: time(),
		));

		$audit = $this->toolService->auditPageOrders($lib->id, $user);

		self::assertSame(2, $audit['totalFiles']);
		self::assertSame(1, $audit['stuckCount']);
		self::assertSame(0, $audit['customCount']);
		self::assertSame(1, $audit['normalCount']);

		$firstItem = $audit['items'][0];
		self::assertSame($file1->id, $firstItem['id']);
		self::assertSame('1-1', $firstItem['pageOrder']);
		self::assertSame(4, $firstItem['pageCount']);
		self::assertSame('1-4', $firstItem['suggestedPageOrder']);
		self::assertTrue($firstItem['isStuckOneToOne']);
	}

	public function testResetPageOrdersInStuckMode(): void {
		$lib = $this->createTestLibrary();
		$this->setupLibraryFolder($lib);
		$user = $this->rootFolder->getUserFolder($lib->userId)->getOwner();

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'stuck-song-uuid',
			libraryId: $lib->id,
			title: 'Sonata in C',
		));

		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'stuck-file-uuid',
			songId: $song->id,
			fileId: 201,
			pageOrder: '1-1',
			pageCount: 5,
			fileSize: 4096,
			type: InternalSongFile::FILE_TYPE_PDF,
			createdAt: time() - 100,
			updatedAt: time() - 100,
		));

		$result = $this->toolService->resetPageOrders($lib->id, $user, null, 'stuck');

		self::assertTrue($result['success']);
		self::assertSame(1, $result['resetCount']);

		$updatedFile = $this->songFileMapper->findById($file->id);
		self::assertNotNull($updatedFile);
		self::assertSame('1-5', $updatedFile->pageOrder);
		self::assertSame(5, $updatedFile->pageCount);
	}

	public function testResetPageOrdersByExplicitFileIds(): void {
		$lib = $this->createTestLibrary();
		$this->setupLibraryFolder($lib);
		$user = $this->rootFolder->getUserFolder($lib->userId)->getOwner();

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'custom-song-uuid',
			libraryId: $lib->id,
			title: 'Custom Order Song',
		));

		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'custom-file-uuid',
			songId: $song->id,
			fileId: 301,
			pageOrder: '1,3,2',
			pageCount: 3,
			fileSize: 1024,
			type: InternalSongFile::FILE_TYPE_PDF,
			createdAt: time() - 100,
			updatedAt: time() - 100,
		));

		$result = $this->toolService->resetPageOrders($lib->id, $user, [$file->id]);

		self::assertTrue($result['success']);
		self::assertSame(1, $result['resetCount']);

		$updatedFile = $this->songFileMapper->findById($file->id);
		self::assertNotNull($updatedFile);
		self::assertSame('1-3', $updatedFile->pageOrder);
	}

	public function testIntegrityFixerResetsStuckPageOrderIssue(): void {
		$lib = $this->createTestLibrary();

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'fixer-song-uuid',
			libraryId: $lib->id,
			title: 'Fixer Test Song',
		));

		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'fixer-file-uuid',
			songId: $song->id,
			fileId: 401,
			pageOrder: '1-1',
			pageCount: 6,
			fileSize: 2048,
			type: InternalSongFile::FILE_TYPE_PDF,
			createdAt: time() - 50,
			updatedAt: time() - 50,
		));

		$fixRes = $this->fixer->fixIssue(
			$lib->id,
			'admin',
			IntegrityIssue::TYPE_STUCK_PAGE_ORDER,
			fileId: $file->id,
		);

		self::assertTrue($fixRes['success']);
		self::assertSame(1, $fixRes['fixedCount']);

		$updated = $this->songFileMapper->findById($file->id);
		self::assertNotNull($updated);
		self::assertSame('1-6', $updated->pageOrder);
	}

	public function testAuditPageOrdersHealsStaleOnePageCountWhenPhysicalPdfHasMorePages(): void {
		$lib = $this->createTestLibrary();
		$this->setupLibraryFolder($lib);
		$user = $this->rootFolder->getUserFolder($lib->userId)->getOwner();
		$userFolder = $this->rootFolder->getUserFolder($lib->userId);
		$libFolder = $userFolder->get(trim($lib->path, '/'));

		// Physical file has 3 pages
		$pdfContent = "<< /Type /Pages /Count 3 /Kids [] >>\n";
		$pdfNode = $libFolder->newFile('multi-page-split.pdf', $pdfContent);
		$this->createdUserNodes[] = $pdfNode;

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'song-uuid-stale',
			libraryId: $lib->id,
			title: 'Song with stale 1-1',
		));

		// Recorded in DB as 1 page with 1-1
		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'file-uuid-stale',
			songId: $song->id,
			fileId: (int)$pdfNode->getId(),
			pageOrder: '1-1',
			pageCount: 1,
			fileSize: strlen($pdfContent),
			type: InternalSongFile::FILE_TYPE_PDF,
			createdAt: time(),
			updatedAt: time(),
		));

		$audit = $this->toolService->auditPageOrders($lib->id, $user);

		self::assertSame(1, $audit['totalFiles']);
		self::assertSame(1, $audit['stuckCount'], 'Physical multi-page file should be classified as stuck at 1-1');
		self::assertTrue($audit['items'][0]['isStuckOneToOne']);
		self::assertSame(3, $audit['items'][0]['actualPageCount']);
		self::assertSame('1-3', $audit['items'][0]['suggestedPageOrder']);

		// Verify database was healed
		$reloaded = $this->songFileMapper->findById($file->id);
		self::assertNotNull($reloaded);
		self::assertSame(3, $reloaded->pageCount);
	}
}
