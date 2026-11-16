<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalSetlistItem;
use OCA\SheetMusic\Db\SublibraryMapper;
use OCA\SheetMusic\Service\SublibraryService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use PDO;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class SublibrarySyncTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private SublibraryService $sublibraryService;
	private int $testLibId;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$subMapper = new SublibraryMapper($this->dbConn);
		$this->sublibraryService = new SublibraryService($subMapper, $this->entityMapper, $this->dbConn);

		$logger = new \Psr\Log\NullLogger();
		$this->exporter = new MobileSheetsSqliteExporter(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$this->libraryMapper,
			$this->pathResolver,
			$this->rootFolder,
			$this->userManager,
			$logger,
			null,
			$this->bookmarkMapper,
			$this->setlistItemMapper,
			null,
			$this->sublibraryService,
		);

		$this->importer = new MobileSheetsSqliteImporter(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$this->libraryMapper,
			$this->pathResolver,
			$this->rootFolder,
			$this->userManager,
			$logger,
			null,
			null,
			$this->setlistItemMapper,
			$this->bookmarkMapper,
			null,
			$this->sublibraryService,
		);

		$lib = $this->createTestLibrary('/SublibSyncTest/scores.db');
		$this->testLibId = (int)$lib->id;
	}

	protected function tearDown(): void {
		$this->cleanupLibraryData($this->testLibId);
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testExportFiltersSongsAndFilesAndSetlists(): void {
		// Song 1: Organ piece
		$song1 = $this->insertTestSong($this->testLibId, 'Organ Prelude');
		$instOrgan = $this->insertTestEntity($this->testLibId, 'instrument', 'Orgel');
		$this->entityMapper->linkSongEntity((int)$song1->id, (int)$instOrgan->id);
		$this->attachTestFileToSong($song1, 'organ_prelude.pdf');

		// Song 2: Organ Toccata
		$song2 = $this->insertTestSong($this->testLibId, 'Organ Toccata');
		$this->entityMapper->linkSongEntity((int)$song2->id, (int)$instOrgan->id);
		$this->attachTestFileToSong($song2, 'organ_toccata.pdf');

		// Song 3: Bass piece
		$song3 = $this->insertTestSong($this->testLibId, 'Bass Solo');
		$instBass = $this->insertTestEntity($this->testLibId, 'instrument', 'Bass');
		$this->entityMapper->linkSongEntity((int)$song3->id, (int)$instBass->id);
		$this->attachTestFileToSong($song3, 'bass_solo.pdf');

		// Setlist A: Contains only organ pieces (Song 1 and Song 2) -> SHOULD export
		$setlistA = $this->insertTestEntity($this->testLibId, 'setlist', 'Organ Recital');
		$this->setlistItemMapper->insert(new InternalSetlistItem(null, (int)$setlistA->id, (int)$song1->id, null, 1));
		$this->setlistItemMapper->insert(new InternalSetlistItem(null, (int)$setlistA->id, (int)$song2->id, null, 2));

		// Setlist B: Mixed pieces (Song 1 and Song 3) -> should NOT export
		$setlistB = $this->insertTestEntity($this->testLibId, 'setlist', 'Mixed Concert');
		$this->setlistItemMapper->insert(new InternalSetlistItem(null, (int)$setlistB->id, (int)$song1->id, null, 1));
		$this->setlistItemMapper->insert(new InternalSetlistItem(null, (int)$setlistB->id, (int)$song3->id, null, 2));

		// Create Organ sublibrary
		$sub = $this->sublibraryService->createSublibrary($this->testLibId, 'Organ Sublib', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($this->testLibId, $exportPath, null, $sub);

		$db = new PDO('sqlite:' . $exportPath);
		$exportedSongs = $db->query('SELECT Title FROM Songs')->fetchAll(PDO::FETCH_COLUMN);
		self::assertCount(2, $exportedSongs);
		self::assertContains('Organ Prelude', $exportedSongs);
		self::assertContains('Organ Toccata', $exportedSongs);
		self::assertNotContains('Bass Solo', $exportedSongs);

		// Check files
		$exportedFiles = $db->query('SELECT Path FROM Files')->fetchAll(PDO::FETCH_COLUMN);
		self::assertCount(2, $exportedFiles);
		self::assertStringContainsString('organ_prelude.pdf', $exportedFiles[0] . $exportedFiles[1]);
		self::assertStringContainsString('organ_toccata.pdf', $exportedFiles[0] . $exportedFiles[1]);

		// Check setlists: Setlist A must be present, Setlist B must NOT
		$exportedSetlists = $db->query('SELECT Name FROM Setlists')->fetchAll(PDO::FETCH_COLUMN);
		self::assertContains('Organ Recital', $exportedSetlists);
		self::assertNotContains('Mixed Concert', $exportedSetlists);

		// Check hashcodes
		$hashcodes = $this->exporter->generateHashcodes($this->testLibId, $sub);
		self::assertStringContainsString('organ_prelude.pdf', $hashcodes);
		self::assertStringContainsString('organ_toccata.pdf', $hashcodes);
		self::assertStringNotContainsString('bass_solo.pdf', $hashcodes);

		@unlink($exportPath);
	}

	public function testImportAutoTagsNewSongWithSublibraryFilter(): void {
		$sub = $this->sublibraryService->createSublibrary($this->testLibId, 'Organ Sublib', 'orgel', [
			'instrument' => ['Orgel', 'Klavier'],
		]);

		$dbPath = $this->tempDbPath();
		$this->exporter->exportToFile($this->testLibId, $dbPath, null, $sub);

		// Simulate client adding a new song on tablet without setting an instrument
		$db = new PDO('sqlite:' . $dbPath);
		$db->exec("INSERT INTO Songs (Title) VALUES ('New Tablet Song')");
		$newClientId = (int)$db->lastInsertId();

		$this->importer->importFromFile($this->testLibId, $dbPath, null, null, $sub);

		$importedSong = $this->songMapper->findByClientId($this->testLibId, $newClientId);
		self::assertNotNull($importedSong);
		self::assertSame('New Tablet Song', $importedSong->title);

		// Verify that "Prevent Dropping" invariant auto-tagged it with 'Orgel'
		$instruments = $this->entityMapper->findEntitiesForSong((int)$importedSong->id, 'instrument');
		self::assertCount(1, $instruments);
		self::assertSame('Orgel', $instruments[0]->name);
		self::assertTrue($this->sublibraryService->matchesSong($sub, (int)$importedSong->id));

		@unlink($dbPath);
	}

	public function testImportDeletesFromSublibraryWithoutDeletingMasterSong(): void {
		// Create an organ song
		$song = $this->insertTestSong($this->testLibId, 'Organ Fugue');
		$instOrgan = $this->insertTestEntity($this->testLibId, 'instrument', 'Orgel');
		$this->entityMapper->linkSongEntity((int)$song->id, (int)$instOrgan->id);
		$this->attachTestFileToSong($song, 'fugue.pdf');

		$sub = $this->sublibraryService->createSublibrary($this->testLibId, 'Organ Sublib', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		// Export baseline database
		$dbPath = $this->tempDbPath();
		$this->exporter->exportToFile($this->testLibId, $dbPath, null, $sub);

		// Tablet deletes the song
		$db = new PDO('sqlite:' . $dbPath);
		$db->exec('DELETE FROM Songs');

		// Import back
		$stats = $this->importer->importFromFile($this->testLibId, $dbPath, null, null, $sub);
		self::assertSame(1, $stats['songsDeleted']);

		// The song must STILL exist in the master library (not soft deleted)
		$masterSong = $this->songMapper->findById((int)$song->id);
		self::assertNotNull($masterSong);
		self::assertNull($masterSong->deletedAt);

		// BUT the 'Orgel' attribute link must be removed so it no longer matches the sublibrary
		$instruments = $this->entityMapper->findEntitiesForSong((int)$song->id, 'instrument');
		self::assertEmpty($instruments);
		self::assertFalse($this->sublibraryService->matchesSong($sub, (int)$song->id));

		@unlink($dbPath);
	}

	public function testImportNewSongWithVirtualSublibraryFilePathResolvesAndCleansUpStub(): void {
		$sub = $this->sublibraryService->createSublibrary($this->testLibId, 'Organ Sublib', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		// 1. Simulate auto-indexer creating a stub song when the score file was uploaded via WebDAV PUT
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folder = $userFolder->get('SublibSyncTest');
		$fileNode = $folder->newFile('20261109_143351.pdf');
		$fileNode->putContent("%PDF-1.4 Test Score\n");
		$this->createdUserNodes[] = $fileNode;

		$stubSong = $this->insertTestSong($this->testLibId, '20261109 143351');
		$stubFile = new \OCA\SheetMusic\Db\InternalSongFile(
			uuid: 'sf_stub_' . bin2hex(random_bytes(4)),
			songId: $stubSong->id,
			fileId: (int)$fileNode->getId(),
			pageOrder: '1-1',
			pageCount: 1,
			fileSize: 1024,
			fileHash: 123456,
			type: 1,
			clientId: null,
			createdAt: time(),
			updatedAt: time(),
		);
		$this->songFileMapper->insert($stubFile);

		// 2. Export baseline database
		$dbPath = $this->tempDbPath();
		$this->exporter->exportToFile($this->testLibId, $dbPath, null, $sub);

		// 3. Simulate tablet adding "Song2" and referencing the file under the virtual sublibrary WebDAV path
		$db = new PDO('sqlite:' . $dbPath);
		$db->exec("INSERT INTO Songs (Title) VALUES ('Song2')");
		$newSongClientId = (int)$db->lastInsertId();

		$storedPath = '/remote.php/dav/files/admin/SublibSyncTest/_SubLibraries_/orgel/20261109_143351.pdf';
		$stmt = $db->prepare("INSERT INTO Files (SongId, Path, PageOrder, SourceFilePageCount, FileSize) VALUES (?, ?, '1-1', 1, 1024)");
		$stmt->execute([$newSongClientId, $storedPath]);

		// 4. Import SQLite database back into server
		$stats = $this->importer->importFromFile($this->testLibId, $dbPath, null, null, $sub);
		self::assertSame(1, $stats['songsCreated']);
		self::assertSame(1, $stats['filesCreated']);

		// 5. Verify Song2 has the file attached
		$song2 = $this->songMapper->findByClientId($this->testLibId, $newSongClientId);
		self::assertNotNull($song2);
		self::assertSame('Song2', $song2->title);

		$files = $this->songFileMapper->findAllForSong((int)$song2->id, includeDeleted: false);
		self::assertCount(1, $files);
		self::assertSame((int)$fileNode->getId(), $files[0]->fileId);

		// 6. Verify stub song was cleaned up
		$danglingSong = $this->songMapper->findById((int)$stubSong->id);
		self::assertNull($danglingSong);

		// 7. Verify Song2 received sublibrary auto-tagging
		$instruments = $this->entityMapper->findEntitiesForSong((int)$song2->id, 'instrument');
		self::assertCount(1, $instruments);
		self::assertSame('Orgel', $instruments[0]->name);

		@unlink($dbPath);
	}

	public function testImportModifyingSongComposerPreservesSublibraryFilter(): void {
		// 1. Master library has a song with composer Foo
		$song = $this->insertTestSong($this->testLibId, 'Sonata in F');
		$composerFoo = $this->insertTestEntity($this->testLibId, 'composer', 'Foo');
		$this->entityMapper->linkSongEntity((int)$song->id, (int)$composerFoo->id);
		$this->attachTestFileToSong($song, 'sonata_f.pdf', clientId: 10);

		// Sublibrary filters on composers = Foo (using plural key to verify normalization)
		$sub = $this->sublibraryService->createSublibrary($this->testLibId, 'Foo Library', 'foo-lib', [
			'composers' => ['Foo'],
		]);

		// 2. Export baseline database for the sublibrary
		$dbPath = $this->tempDbPath();
		$this->exporter->exportToFile($this->testLibId, $dbPath, null, $sub);

		// 3. Client changes composer on tablet from Foo to Bar
		$db = new PDO('sqlite:' . $dbPath);
		$songClientId = (int)$db->query("SELECT Id FROM Songs WHERE Title = 'Sonata in F'")->fetchColumn();
		self::assertGreaterThan(0, $songClientId);

		$db->exec("INSERT INTO Composer (Name) VALUES ('Bar')");
		$barId = (int)$db->lastInsertId();

		// Replace Foo link with Bar link
		$db->exec("DELETE FROM ComposerSongs WHERE SongId = $songClientId");
		$stmt = $db->prepare('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (?, ?)');
		$stmt->execute([$barId, $songClientId]);

		// 4. Import back
		$this->importer->importFromFile($this->testLibId, $dbPath, null, null, $sub);

		// 5. Verify that Bar was added AND Foo was retained to prevent dropping from the sublibrary
		$composers = $this->entityMapper->findEntitiesForSong((int)$song->id, 'composer');
		$composerNames = array_map(static fn ($c) => $c->name, $composers);

		self::assertContains('Bar', $composerNames);
		self::assertContains('Foo', $composerNames);
		self::assertTrue($this->sublibraryService->matchesSong($sub, (int)$song->id));

		@unlink($dbPath);
	}
}
