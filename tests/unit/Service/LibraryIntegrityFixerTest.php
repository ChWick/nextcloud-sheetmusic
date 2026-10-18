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

		// Now test fixing the misc data file
		$resMisc = $this->fixer->fixIssue(
			$lib->id,
			'admin',
			IntegrityIssue::TYPE_MISSING_FILE,
			fileId: 99,
		);
		self::assertTrue($resMisc['success']);

		// Misc data for 99 was deleted
		$miscRows = $this->miscMapper->getMiscDataForTable($lib->id, 'mobilesheets', 'Files', includeDeleted: false);
		self::assertEmpty($miscRows);
	}
}
