<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Tests\Unit\Service;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\IntegrityIssue;
use OCA\SheetMusic\Service\LibraryIntegrityFixer;
use OCA\SheetMusic\Service\LibraryOrphanScanner;
use OCA\SheetMusic\Service\LibraryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryIntegrityFixerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LibraryIntegrityFixer $fixer;

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
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testIsRelationalIssueType(): void {
		self::assertTrue($this->fixer->isRelationalIssueType(IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT));
		self::assertTrue($this->fixer->isRelationalIssueType(IntegrityIssue::TYPE_ORPHAN_SONG_FILE));
		self::assertTrue($this->fixer->isRelationalIssueType(IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE));
		self::assertTrue($this->fixer->isRelationalIssueType(IntegrityIssue::TYPE_ORPHAN_MISC_DATA));
		self::assertTrue($this->fixer->isRelationalIssueType(IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID));
		self::assertFalse($this->fixer->isRelationalIssueType(IntegrityIssue::TYPE_MISSING_FILE));
		self::assertFalse($this->fixer->isRelationalIssueType(IntegrityIssue::TYPE_INVALID_NAMING_RULE));
	}

	public function testDescribeRelationalFix(): void {
		self::assertSame(
			'Removed 3 orphan entity assignment(s).',
			$this->fixer->describeRelationalFix(IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT, 3),
		);
		self::assertSame(
			'No orphan entity assignments remained to fix.',
			$this->fixer->describeRelationalFix(IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT, 0),
		);
		self::assertSame(
			'Reset 2 duplicate client_id record(s).',
			$this->fixer->describeRelationalFix(IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID, 2),
		);
	}

	public function testFixRelationalIssueWhenNoRecordsExistReturnsZero(): void {
		$res = $this->fixer->fixRelationalIssue(999, IntegrityIssue::TYPE_ORPHAN_SONG_FILE);
		self::assertSame(0, $res);
	}

	public function testFixMissingFileRemovesFromInternalFilesAndMiscData(): void {
		$lib = $this->createTestLibrary();
		$this->miscMapper->saveMiscData($lib->id, 'mobilesheets', 'Files', '99', [
			'Id' => 99,
			'SongId' => 888,
			'Path' => '/some/missing/file.pdf',
		]);

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'test-song-uuid',
			libraryId: $lib->id,
			title: 'Song with missing file',
		));

		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'test-file-uuid',
			songId: $song->id,
			fileId: 12345,
			fileSize: 100,
			type: 1,
			createdAt: time(),
			updatedAt: time(),
		));

		$res = $this->fixer->fixIssue(
			$lib->id,
			'admin',
			IntegrityIssue::TYPE_MISSING_FILE,
			fileId: $file->id,
		);

		self::assertTrue($res['success']);
		self::assertNotNull($this->songFileMapper->findById($file->id)->deletedAt);
		self::assertEmpty($this->songFileMapper->findAllForSong($song->id));
	}

	public function testFixPageCountMismatchUpdatesDatabase(): void {
		$lib = $this->createTestLibrary();
		$userFolder = $this->rootFolder->getUserFolder($lib->userId);
		$libPath = trim($lib->path, '/');
		if (!$userFolder->nodeExists($libPath)) {
			$userFolder->newFolder($libPath);
		}
		$libFolder = $userFolder->get($libPath);

		// Physical file has 4 pages
		$pdfContent = "<< /Type /Pages /Count 4 /Kids [] >>\n";
		$pdfNode = $libFolder->newFile('mismatch.pdf', $pdfContent);

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'test-song-mismatch',
			libraryId: $lib->id,
			title: 'Mismatch Song',
		));

		// Stored with stale count of 1 and order 1-1
		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'test-file-mismatch',
			songId: $song->id,
			fileId: (int)$pdfNode->getId(),
			pageOrder: '1-1',
			pageCount: 1,
			fileSize: strlen($pdfContent),
			type: InternalSongFile::FILE_TYPE_PDF,
			createdAt: time(),
			updatedAt: time(),
		));

		$res = $this->fixer->fixIssue(
			$lib->id,
			$lib->userId,
			IntegrityIssue::TYPE_PAGE_COUNT_MISMATCH,
			fileId: $file->id,
		);

		self::assertTrue($res['success']);
		self::assertSame(1, $res['fixedCount']);

		$updated = $this->songFileMapper->findById($file->id);
		self::assertNotNull($updated);
		self::assertSame(4, $updated->pageCount);
		self::assertSame('1-1', $updated->pageOrder);
	}

	public function testFixStuckPageOrderUpdatesDatabase(): void {
		$lib = $this->createTestLibrary();
		$userFolder = $this->rootFolder->getUserFolder($lib->userId);
		$libPath = trim($lib->path, '/');
		if (!$userFolder->nodeExists($libPath)) {
			$userFolder->newFolder($libPath);
		}
		$libFolder = $userFolder->get($libPath);

		// Physical file has 3 pages
		$pdfContent = "<< /Type /Pages /Count 3 /Kids [] >>\n";
		$pdfNode = $libFolder->newFile('stuck.pdf', $pdfContent);

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'test-song-stuck',
			libraryId: $lib->id,
			title: 'Stuck Song',
		));

		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'test-file-stuck',
			songId: $song->id,
			fileId: (int)$pdfNode->getId(),
			pageOrder: '1-1',
			pageCount: 1,
			fileSize: strlen($pdfContent),
			type: InternalSongFile::FILE_TYPE_PDF,
			createdAt: time(),
			updatedAt: time(),
		));

		$res = $this->fixer->fixIssue(
			$lib->id,
			$lib->userId,
			IntegrityIssue::TYPE_STUCK_PAGE_ORDER,
			fileId: $file->id,
		);

		self::assertTrue($res['success']);
		self::assertSame(1, $res['fixedCount']);

		$updated = $this->songFileMapper->findById($file->id);
		self::assertNotNull($updated);
		self::assertSame(3, $updated->pageCount);
		self::assertSame('1-3', $updated->pageOrder);
	}
}
