<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Service\LibraryReconciler;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryReconcilerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LibraryReconciler $reconciler;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->reconciler = new LibraryReconciler(
			$this->songMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->rootFolder,
			$this->userManager,
			new NullLogger(),
			$this->entityMapper,
			$this->bookmarkMapper,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testReconcileIndexesNewFilesAndPreservesMissingTrackedFiles(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folderName = 'Reconcile_' . bin2hex(random_bytes(4));
		$libFolder = $userFolder->newFolder($folderName);
		$this->createdUserNodes[] = $libFolder;

		$library = $this->createTestLibrary('/' . $folderName . '/lib.db', 'admin');

		// 1. Create a physical untracked file on disk
		$physicalFile = $libFolder->newFile('001 New Score.pdf');
		$physicalFile->putContent("%PDF-1.4 mock content\n");
		$this->createdUserNodes[] = $physicalFile;

		// 2. Create an existing tracked file in database with non-existent fileId 999999
		$staleSong = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'stale-song',
			libraryId: (int)$library->id,
			title: 'Stale Song',
		));
		$staleFile = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'stale-file',
			songId: (int)$staleSong->id,
			fileId: 999999,
		));

		// Run reconciliation
		$result = $this->reconciler->reconcile($library);

		self::assertSame(1, $result['indexed']);
		self::assertSame(0, $result['pruned']);

		// The new score should now be indexed as a song and file in database
		$indexedSong = $this->songMapper->findByTitle((int)$library->id, 'New Score');
		self::assertNotNull($indexedSong);
		self::assertSame('New Score', $indexedSong->title);

		$indexedFiles = $this->songFileMapper->findAllForSong((int)$indexedSong->id);
		self::assertCount(1, $indexedFiles);
		self::assertSame((int)$physicalFile->getId(), $indexedFiles[0]->fileId);

		// The stale file should NOT be pruned (its attachment is preserved for missing file detection)
		$reloadedStale = $this->songFileMapper->findById((int)$staleFile->id);
		self::assertNotNull($reloadedStale);
		self::assertNull($reloadedStale->deletedAt);
	}

	public function testExtractTitleFromFilenameRemovesNumericPrefixes(): void {
		self::assertSame('Jazz - Take Five', $this->reconciler->extractTitleFromFilename('001 Jazz - Take Five.pdf'));
		self::assertSame('Moonlight Sonata', $this->reconciler->extractTitleFromFilename('Moonlight_Sonata.pdf'));
		self::assertSame('Concerto in G', $this->reconciler->extractTitleFromFilename('Concerto in G.pdf'));
	}

	public function testIsIgnoredFilename(): void {
		self::assertTrue($this->reconciler->isIgnoredFilename('mobilesheets.db'));
		self::assertTrue($this->reconciler->isIgnoredFilename('mobilesheets.db-wal'));
		self::assertTrue($this->reconciler->isIgnoredFilename('my_library.db'));
		self::assertTrue($this->reconciler->isIgnoredFilename('mobilesheets_hashcodes.txt'));
		self::assertFalse($this->reconciler->isIgnoredFilename('Sonata.pdf'));
	}

	public function testReconcileWithImportPdfMetadataExtractsMetadataAndSharesEntities(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folderName = 'ReconcilePdf_' . bin2hex(random_bytes(4));
		$libFolder = $userFolder->newFolder($folderName);
		$this->createdUserNodes[] = $libFolder;

		$library = $this->createTestLibrary('/' . $folderName . '/lib.db', 'admin');

		// Create two PDFs with metadata: both share composer and collection
		$pdfAContent = MobileSheetsPdfMetadata::withInfo(self::minimalPdf(), [
			'Title' => 'Moonlight Sonata',
			'Composer' => 'Ludwig van Beethoven',
			'Collection' => 'Classical Masterpieces',
			'Artist' => 'Solo Piano',
		]);
		$pdfBContent = MobileSheetsPdfMetadata::withInfo(self::minimalPdf(), [
			'Title' => 'Symphony No. 5',
			'Composer' => 'Ludwig van Beethoven',
			'Collection' => 'Classical Masterpieces',
			'Artist' => 'Vienna Philharmonic',
		]);

		$fileA = $libFolder->newFile('001_some_file_a.pdf');
		$fileA->putContent($pdfAContent);
		$this->createdUserNodes[] = $fileA;

		$fileB = $libFolder->newFile('002_some_file_b.pdf');
		$fileB->putContent($pdfBContent);
		$this->createdUserNodes[] = $fileB;

		// Reconcile with $importPdfMetadata = true
		$result = $this->reconciler->reconcile($library, null, true);

		self::assertSame(2, $result['indexed']);

		// Titles should match the PDF metadata (not the filenames)
		$songA = $this->songMapper->findByTitle((int)$library->id, 'Moonlight Sonata');
		self::assertNotNull($songA);
		self::assertSame('Moonlight Sonata', $songA->title);

		$songB = $this->songMapper->findByTitle((int)$library->id, 'Symphony No. 5');
		self::assertNotNull($songB);
		self::assertSame('Symphony No. 5', $songB->title);

		// Song A entities
		$songAComposers = $this->entityMapper->findEntitiesForSong((int)$songA->id, 'composer');
		self::assertCount(1, $songAComposers);
		self::assertSame('Ludwig van Beethoven', $songAComposers[0]->name);

		$songACollections = $this->entityMapper->findEntitiesForSong((int)$songA->id, 'collection');
		self::assertCount(1, $songACollections);
		self::assertSame('Classical Masterpieces', $songACollections[0]->name);

		// Song B entities
		$songBComposers = $this->entityMapper->findEntitiesForSong((int)$songB->id, 'composer');
		self::assertCount(1, $songBComposers);
		self::assertSame('Ludwig van Beethoven', $songBComposers[0]->name);

		$songBCollections = $this->entityMapper->findEntitiesForSong((int)$songB->id, 'collection');
		self::assertCount(1, $songBCollections);
		self::assertSame('Classical Masterpieces', $songBCollections[0]->name);

		// Crucial requirement: identical composers and collections share the same internal entity ID
		self::assertSame($songAComposers[0]->id, $songBComposers[0]->id);
		self::assertSame($songACollections[0]->id, $songBCollections[0]->id);

		// Distinct artists get distinct IDs
		$songAArtists = $this->entityMapper->findEntitiesForSong((int)$songA->id, 'artist');
		$songBArtists = $this->entityMapper->findEntitiesForSong((int)$songB->id, 'artist');
		self::assertCount(1, $songAArtists);
		self::assertCount(1, $songBArtists);
		self::assertSame('Solo Piano', $songAArtists[0]->name);
		self::assertSame('Vienna Philharmonic', $songBArtists[0]->name);
		self::assertNotSame($songAArtists[0]->id, $songBArtists[0]->id);

		// Total composers in library must be exactly 1
		$allComposers = $this->entityMapper->findAllForLibrary((int)$library->id, 'composer');
		self::assertCount(1, $allComposers);

		// Total collections in library must be exactly 1
		$allCollections = $this->entityMapper->findAllForLibrary((int)$library->id, 'collection');
		self::assertCount(1, $allCollections);
	}

	public function testReconcileWithImportPdfMetadataFalseUsesFilenameAndCreatesNoEntities(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folderName = 'ReconcileNoPdf_' . bin2hex(random_bytes(4));
		$libFolder = $userFolder->newFolder($folderName);
		$this->createdUserNodes[] = $libFolder;

		$library = $this->createTestLibrary('/' . $folderName . '/lib.db', 'admin');

		$pdfContent = MobileSheetsPdfMetadata::withInfo(self::minimalPdf(), [
			'Title' => 'Metadata Title',
			'Composer' => 'Beethoven',
		]);

		$file = $libFolder->newFile('001 File Title.pdf');
		$file->putContent($pdfContent);
		$this->createdUserNodes[] = $file;

		// Reconcile with $importPdfMetadata = false
		$result = $this->reconciler->reconcile($library, null, false);

		self::assertSame(1, $result['indexed']);

		// Title should come from filename, not PDF metadata
		$song = $this->songMapper->findByTitle((int)$library->id, 'File Title');
		self::assertNotNull($song);
		self::assertNull($this->songMapper->findByTitle((int)$library->id, 'Metadata Title'));

		// No entities linked
		$composers = $this->entityMapper->findEntitiesForSong((int)$song->id, 'composer');
		self::assertEmpty($composers);
	}

	public function testReconcileExtractsPdfBookmarksWhenPresent(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folderName = 'Reconcile_Bm_' . bin2hex(random_bytes(4));
		$libFolder = $userFolder->newFolder($folderName);
		$this->createdUserNodes[] = $libFolder;

		$library = $this->createTestLibrary('/' . $folderName . '/lib.db', 'admin');

		// Create a PDF with outline bookmarks
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R /Outlines 5 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 3 /Kids [3 0 R 4 0 R 7 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "4 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "7 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "5 0 obj << /Type /Outlines /First 6 0 R /Last 8 0 R /Count 2 >> endobj\n"
			. "6 0 obj << /Title (Movement 1: Allegro) /Parent 5 0 R /Next 8 0 R /Dest [3 0 R /Fit] >> endobj\n"
			. "8 0 obj << /Title (Movement 2: Adagio) /Parent 5 0 R /Prev 6 0 R /Dest [4 0 R /Fit] >> endobj\n";

		$xrefOffset = strlen("%PDF-1.4\n" . $objects);
		$pdf = "%PDF-1.4\n"
			. $objects
			. "xref\n0 9\n0000000000 65535 f \n"
			. "trailer << /Size 9 /Root 1 0 R >>\n"
			. "startxref\n{$xrefOffset}\n%%EOF\n";

		$file = $libFolder->newFile('Sonata.pdf');
		$file->putContent($pdf);
		$this->createdUserNodes[] = $file;

		$result = $this->reconciler->reconcile($library, importPdfMetadata: true);
		self::assertSame(1, $result['indexed']);

		$song = $this->songMapper->findByTitle((int)$library->id, 'Sonata');
		self::assertNotNull($song);

		$bookmarks = $this->bookmarkMapper->findForSong((int)$song->id);
		self::assertCount(2, $bookmarks);
		self::assertSame('Movement 1: Allegro', $bookmarks[0]->name);
		self::assertSame(1, $bookmarks[0]->pageNum);
		self::assertSame(1, $bookmarks[0]->endPage);

		self::assertSame('Movement 2: Adagio', $bookmarks[1]->name);
		self::assertSame(2, $bookmarks[1]->pageNum);
		self::assertSame(3, $bookmarks[1]->endPage);
	}

	private static function minimalPdf(): string {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "4 0 obj << /Title (Old title) /Creator (Unit test) >> endobj\n";

		$xrefOffset = strlen("%PDF-1.4\n" . $objects);

		return "%PDF-1.4\n"
			. $objects
			. "xref\n0 5\n0000000000 65535 f \n"
			. "trailer << /Size 5 /Root 1 0 R /Info 4 0 R >>\n"
			. "startxref\n{$xrefOffset}\n%%EOF\n";
	}
}
