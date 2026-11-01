<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCP\Files\File;
use PDO;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsSqliteImporterTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private string $tempDbPath;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->tempDbPath = tempnam(sys_get_temp_dir(), 'sm_import_') . '.db';
	}

	protected function tearDown(): void {
		if (file_exists($this->tempDbPath)) {
			@unlink($this->tempDbPath);
		}
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	private function createTestDatabase(): PDO {
		$db = new PDO('sqlite:' . $this->tempDbPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE SongNotes(Id INTEGER PRIMARY KEY, SongId INTEGER, Note TEXT, Title VARCHAR(255) DEFAULT \'\', DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');

		return $db;
	}

	public function testImportFreshDatabaseCreatesSongsEntitiesLinksAndFiles(): void {
		$library = $this->createTestLibrary('Noten', 'admin');
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$libFolder = $userFolder->nodeExists('Noten') ? $userFolder->get('Noten') : $userFolder->newFolder('Noten');
		$fileNode = $libFolder->nodeExists('Debussy.pdf') ? $libFolder->get('Debussy.pdf') : $libFolder->newFile('Debussy.pdf');
		$fileNode->putContent("%PDF-1.4 Debussy\n");
		$this->createdUserNodes[] = $fileNode;

		$db = $this->createTestDatabase();

		// Insert song
		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (50, 'Clair de Lune', 'Clair de Lune', 4, 3, 300, 'piano', 'Impressionism', '', 50, 1000000, 1700000000000)");
		// Insert note
		$db->exec("INSERT INTO SongNotes (SongId, Note) VALUES (50, 'Andante très expressif')");
		// Insert composer
		$db->exec("INSERT INTO Composer (Id, Name, DateCreated, LastModified) VALUES (60, 'Claude Debussy', 1000000, 1700000000000)");
		// Insert link
		$db->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (60, 50)');
		// Insert file
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES (70, 50, '/remote.php/dav/files/admin/Noten/Debussy.pdf', '1-3', 204800, 1700000000000, 1, 1, 3, 555666)");
		// Insert misc table Bookmarks
		$db->exec('CREATE TABLE Bookmarks (Id INTEGER PRIMARY KEY, SongId INTEGER, Name VARCHAR(255), PageNum INTEGER)');
		$db->exec("INSERT INTO Bookmarks (Id, SongId, Name, PageNum) VALUES (1, 50, 'Climactic Theme', 2)");

		$stats = $this->importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(1, $stats['songsCreated']);
		self::assertSame(1, $stats['entitiesCreated']);
		self::assertSame(1, $stats['filesCreated']);
		self::assertSame(1, $stats['miscTablesSynced']);

		// Verify created song
		$createdSong = $this->songMapper->findByClientId((int)$library->id, 50);
		self::assertNotNull($createdSong);
		self::assertSame('Clair de Lune', $createdSong->title);
		self::assertSame(4, $createdSong->rating);
		self::assertSame(3, $createdSong->difficulty);
		self::assertSame(300, $createdSong->duration);
		self::assertSame('piano', $createdSong->keywords);
		self::assertSame('Andante très expressif', $createdSong->notes);

		// Verify custom attributes
		self::assertSame('Impressionism', $this->attributeMapper->findForSong((int)$createdSong->id, 'custom'));

		// Verify created composer entity
		$createdEntity = $this->entityMapper->findByClientId((int)$library->id, 'composer', 60);
		self::assertNotNull($createdEntity);
		self::assertSame('Claude Debussy', $createdEntity->name);
		self::assertSame('composer', $createdEntity->type);

		// Verify song entity link
		$entityIds = $this->entityMapper->findEntityIdsForSong((int)$createdSong->id, 'composer');
		self::assertSame([(int)$createdEntity->id], $entityIds);

		// Verify created file
		$files = $this->songFileMapper->findAllForSong((int)$createdSong->id);
		self::assertCount(1, $files);
		$createdFile = $files[0];
		self::assertSame((int)$fileNode->getId(), $createdFile->fileId);
		self::assertSame('1-3', $createdFile->pageOrder);
		self::assertSame(555666, $createdFile->fileHash);
		self::assertSame(70, $createdFile->clientId);
	}

	public function testLwwMergeMobileSheetsIsNewerUpdatesInternalSong(): void {
		$library = $this->createTestLibrary('Noten', 'admin');

		// Existing internal song with older updatedAt = 1000
		$this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'uuid-1',
			libraryId: (int)$library->id,
			title: 'Old Title',
			rating: 3,
			clientId: 10,
			updatedAt: 1000,
		));

		$db = $this->createTestDatabase();
		// MobileSheets timestamp: 2000 in seconds = 2000000 ms
		$db->exec("INSERT INTO Songs (Id, Title, Stars, SongId, LastModified) VALUES (10, 'Updated Title', 5, 10, 2000000)");

		$stats = $this->importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(1, $stats['songsUpdated']);
		$updatedSong = $this->songMapper->findByClientId((int)$library->id, 10);
		self::assertNotNull($updatedSong);
		self::assertSame('Updated Title', $updatedSong->title);
		self::assertSame(5, $updatedSong->rating);
		self::assertSame(2000, $updatedSong->updatedAt);
	}

	public function testLwwMergeNextcloudIsNewerRetainsInternalSong(): void {
		$library = $this->createTestLibrary('Noten', 'admin');

		// Existing internal song with newer updatedAt = 2000
		$this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'uuid-1',
			libraryId: (int)$library->id,
			title: 'Nextcloud Title',
			rating: 5,
			clientId: 10,
			updatedAt: 2000,
		));

		$db = $this->createTestDatabase();
		// MobileSheets timestamp: 1000 in seconds = 1000000 ms
		$db->exec("INSERT INTO Songs (Id, Title, Stars, SongId, LastModified) VALUES (10, 'Client Title', 1, 10, 1000000)");

		$stats = $this->importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(0, $stats['songsUpdated']);
		$retainedSong = $this->songMapper->findByClientId((int)$library->id, 10);
		self::assertNotNull($retainedSong);
		self::assertSame('Nextcloud Title', $retainedSong->title);
	}

	public function testTombstoneProtectionDeletedInNextcloudNotResurrectedByOlderClient(): void {
		$library = $this->createTestLibrary('Noten', 'admin');

		// Soft-deleted in Nextcloud at timestamp 2000 (after 1000)
		$this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'uuid-1',
			libraryId: (int)$library->id,
			title: 'Old Song',
			clientId: 10,
			updatedAt: 1000,
			deletedAt: 2000,
		));

		$db = $this->createTestDatabase();
		// MobileSheets has LastModified 1000 in seconds = 1000000 ms
		$db->exec("INSERT INTO Songs (Id, Title, SongId, LastModified) VALUES (10, 'Old Song', 10, 1000000)");

		$stats = $this->importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(0, $stats['songsUpdated']);
		$fetched = $this->songMapper->findByClientId((int)$library->id, 10);
		self::assertNotNull($fetched);
		self::assertSame(2000, $fetched->deletedAt);
	}

	public function testDeletionsInMobileSheetsSoftDeletesInternalSong(): void {
		$library = $this->createTestLibrary('Noten', 'admin');

		$songToDelete = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'deleted-uuid',
			libraryId: (int)$library->id,
			title: 'Song to Delete',
			clientId: 10,
			deletedAt: null,
		));

		$this->attachTestFileToSong($songToDelete, 'attached.pdf', 20);

		$metaUpdatedAt = time() + 100;
		$db = $this->createTestDatabase();
		// Metadata recorded song with Id 10
		$db->exec('CREATE TABLE _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))');
		$db->exec("INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at) VALUES ('song', 'deleted-uuid', 10, $metaUpdatedAt)");
		// But Songs table is EMPTY (user deleted song on MobileSheets!)

		$stats = $this->importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(1, $stats['songsDeleted']);
		self::assertSame(1, $stats['filesDeleted']);

		$deletedSong = $this->songMapper->findByUuid('deleted-uuid');
		self::assertNotNull($deletedSong);
		self::assertNotNull($deletedSong->deletedAt);
	}

	public function testImportDatabasePreservesInstrumentEntityTypeAndLinks(): void {
		$library = $this->createTestLibrary('Noten', 'admin');

		$db = $this->createTestDatabase();
		$db->exec('CREATE TABLE CustomGroup (Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE CustomGroupSongs (Id INTEGER PRIMARY KEY, GroupId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))');

		// Insert song
		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, SongId, DateCreated, LastModified) VALUES (1, 'Toccata', 'Toccata', 1, 1000, 1700000000000)");

		// Insert CustomGroup: Organ (instrument) and Jazz Trio (customgroup)
		$db->exec("INSERT INTO CustomGroup (Id, Name, DateCreated, LastModified) VALUES (10, 'Organ', 1000, 1700000000000)");
		$db->exec("INSERT INTO CustomGroup (Id, Name, DateCreated, LastModified) VALUES (20, 'Jazz Trio', 1000, 1700000000000)");

		// Insert links
		$db->exec('INSERT INTO CustomGroupSongs (GroupId, SongId) VALUES (10, 1)');
		$db->exec('INSERT INTO CustomGroupSongs (GroupId, SongId) VALUES (20, 1)');

		// Insert sync metadata
		$db->exec("INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES ('entity:instrument', 'uuid-organ', 10, 1700000, '{\"original_type\":\"instrument\",\"name\":\"Organ\"}')");
		$db->exec("INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES ('entity:customgroup', 'uuid-trio', 20, 1700000, '{\"original_type\":\"customgroup\",\"name\":\"Jazz Trio\"}')");

		$stats = $this->importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(2, $stats['entitiesCreated']);

		$organ = $this->entityMapper->findByClientId((int)$library->id, 'instrument', 10);
		self::assertNotNull($organ);
		self::assertSame('instrument', $organ->type);
		self::assertSame('Organ', $organ->name);

		$trio = $this->entityMapper->findByClientId((int)$library->id, 'customgroup', 20);
		self::assertNotNull($trio);
		self::assertSame('customgroup', $trio->type);
		self::assertSame('Jazz Trio', $trio->name);

		$song = $this->songMapper->findByClientId((int)$library->id, 1);
		self::assertNotNull($song);
		$instrumentIds = $this->entityMapper->findEntityIdsForSong((int)$song->id, 'instrument');
		self::assertSame([(int)$organ->id], $instrumentIds);
		$customGroupIds = $this->entityMapper->findEntityIdsForSong((int)$song->id, 'customgroup');
		self::assertSame([(int)$trio->id], $customGroupIds);
	}

	public function testImportAndExportProducesEquivalentDatabaseAndHashmap(): void {
		$library = $this->createTestLibrary('tmp/SheetMusic', 'admin');
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folder = $userFolder->nodeExists('tmp/SheetMusic') ? $userFolder->get('tmp/SheetMusic') : $userFolder->newFolder('tmp/SheetMusic');
		$file = $folder->nodeExists('Bump City.pdf') ? $folder->get('Bump City.pdf') : $folder->newFile('Bump City.pdf');
		$file->putContent("%PDF-1.4 Fake PDF\n");
		$this->createdUserNodes[] = $file;

		$origDb = new PDO('sqlite:' . $this->tempDbPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$origDb->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$origDb->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER, Height INTEGER)');
		$origDb->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE Artists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE ArtistsSongs(Id INTEGER PRIMARY KEY, ArtistId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE Books(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY, BookId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE Genres(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE GenresSongs(Id INTEGER PRIMARY KEY, GenreId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE Keys(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE CustomGroup(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE CustomGroupSongs(Id INTEGER PRIMARY KEY, GroupId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE Years(Id INTEGER PRIMARY KEY, Year INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE YearsSongs(Id INTEGER PRIMARY KEY, YearId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE Signature(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE SignatureSongs(Id INTEGER PRIMARY KEY, SignatureId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE SourceTypes(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE SourceTypeSongs(Id INTEGER PRIMARY KEY, SourceTypeId INTEGER, SongId INTEGER)');
		$origDb->exec('CREATE TABLE Bookmarks(Id INTEGER PRIMARY KEY, SongId INTEGER, Name VARCHAR(255), PageNum INTEGER, ShowInLibrary INTEGER DEFAULT 0, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE AudioFiles(Id INTEGER PRIMARY KEY, SongId INTEGER, Title VARCHAR(255), File VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$origDb->exec('CREATE TABLE MetronomeSettings(Id INTEGER PRIMARY KEY, SongId INTEGER, Bpm INTEGER, Sig1 INTEGER DEFAULT 4, Sig2 INTEGER DEFAULT 4)');
		$origDb->exec('CREATE TABLE Setlists(Id INTEGER PRIMARY KEY, Title VARCHAR(255), DateCreated INTEGER, LastModified INTEGER)');

		// Insert rows
		$origDb->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified, Opus, CatalogNumber, SongNumber) VALUES 
		(1, 'Bump City', 'Bump City', 5, 4, 300, 'funk, horn', 'Tower of Power', 'Live 1974', 1, 1000000, 1700000000000, 'Op. 1', 'Cat. 99', '1')");
		$origDb->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, Password, SourceFilePageCount, FileHash, Width, Height) VALUES 
		(10, 1, '/remote.php/webdav/tmp/SheetMusic/Bump City.pdf', '1-5', 1048576, 1700000000000, 1, 1, '', 5, 12345678, 1000, 1400)");
		$origDb->exec("INSERT INTO Composer (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES (20, 'Emilio Castillo', 1, 1, 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO ComposerSongs (Id, ComposerId, SongId) VALUES (1, 20, 1)');
		$origDb->exec("INSERT INTO Artists (Id, Name, DateCreated, LastModified) VALUES (30, 'Tower Of Power', 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO ArtistsSongs (Id, ArtistId, SongId) VALUES (1, 30, 1)');
		$origDb->exec("INSERT INTO Books (Id, Title, SortBy, Ascending, DateCreated, LastModified) VALUES (40, 'Funk Collection', 1, 1, 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO BookSongs (Id, BookId, SongId) VALUES (1, 40, 1)');
		$origDb->exec("INSERT INTO Collections (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES (50, 'Live Setlist', 1, 1, 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO CollectionSong (Id, CollectionId, SongId) VALUES (1, 50, 1)');
		$origDb->exec("INSERT INTO Genres (Id, Type, DateCreated, LastModified) VALUES (60, 'Funk', 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO GenresSongs (Id, GenreId, SongId) VALUES (1, 60, 1)');
		$origDb->exec("INSERT INTO Keys (Id, Name, DateCreated, LastModified) VALUES (70, 'F# Minor', 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO KeySongs (Id, KeyId, SongId) VALUES (1, 70, 1)');
		$origDb->exec("INSERT INTO CustomGroup (Id, Name, DateCreated, LastModified) VALUES (80, 'Horn Section', 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO CustomGroupSongs (Id, GroupId, SongId) VALUES (1, 80, 1)');
		$origDb->exec('INSERT INTO Years (Id, Year, DateCreated, LastModified) VALUES (90, 1972, 1000000, 1700000000000)');
		$origDb->exec('INSERT INTO YearsSongs (Id, YearId, SongId) VALUES (1, 90, 1)');
		$origDb->exec("INSERT INTO Signature (Id, Name, DateCreated, LastModified) VALUES (100, '4/4', 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO SignatureSongs (Id, SignatureId, SongId) VALUES (1, 100, 1)');
		$origDb->exec("INSERT INTO SourceTypes (Id, Type, DateCreated, LastModified) VALUES (110, 'Manuscript', 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO SourceTypeSongs (Id, SourceTypeId, SongId) VALUES (1, 110, 1)');
		$origDb->exec("INSERT INTO Bookmarks (Id, SongId, Name, PageNum, ShowInLibrary, DateCreated, LastModified) VALUES (1, 1, 'Sax Solo', 3, 1, 1000000, 1700000000000)");
		$origDb->exec("INSERT INTO AudioFiles (Id, SongId, Title, File, DateCreated, LastModified) VALUES (1, 1, 'Live Audio', 'bump_city.mp3', 1000000, 1700000000000)");
		$origDb->exec('INSERT INTO MetronomeSettings (Id, SongId, Bpm, Sig1, Sig2) VALUES (1, 1, 115, 4, 4)');
		$origDb->exec("INSERT INTO Setlists (Id, Title, DateCreated, LastModified) VALUES (1, 'Saturday Night Gig', 1000000, 1700000000000)");

		// 1. Import from MobileSheets DB
		$stats = $this->importer->importFromFile($library, $this->tempDbPath);
		self::assertSame(1, $stats['songsCreated']);
		self::assertSame(10, $stats['entitiesCreated']);
		self::assertSame(1, $stats['filesCreated']);

		// 2. Export back to SQLite DB
		$exportedDbPath = tempnam(sys_get_temp_dir(), 'exported_test_') . '.db';
		try {
			$this->exporter->exportToFile($library, $exportedDbPath);
			self::assertFileExists($exportedDbPath);

			$exportedDb = new PDO('sqlite:' . $exportedDbPath, options: [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);

			// Verify all tables from original DB exist in exported DB
			$origTables = $origDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
			$exportedTables = $exportedDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

			foreach ($origTables as $table) {
				self::assertContains($table, $exportedTables, "Table '$table' should exist in exported database");

				$origRows = $origDb->query("SELECT * FROM \"$table\"")->fetchAll(PDO::FETCH_ASSOC);
				$exportedRows = $exportedDb->query("SELECT * FROM \"$table\"")->fetchAll(PDO::FETCH_ASSOC);

				self::assertCount(count($origRows), $exportedRows, "Row count mismatch in table '$table'");

				if ($table === 'Songs') {
					self::assertSame($origRows[0]['Title'], $exportedRows[0]['Title']);
					self::assertSame((int)$origRows[0]['Stars'], (int)$exportedRows[0]['Stars']);
					self::assertSame($origRows[0]['Custom'], $exportedRows[0]['Custom']);
					self::assertSame($origRows[0]['Custom2'], $exportedRows[0]['Custom2']);
					self::assertSame($origRows[0]['Opus'], $exportedRows[0]['Opus']);
				} elseif ($table === 'MetronomeSettings') {
					self::assertSame((int)$origRows[0]['Bpm'], (int)$exportedRows[0]['Bpm']);
					self::assertSame((int)$origRows[0]['Sig1'], (int)$exportedRows[0]['Sig1']);
					self::assertSame((int)$origRows[0]['Sig2'], (int)$exportedRows[0]['Sig2']);
				} elseif ($table === 'Setlists') {
					self::assertSame($origRows[0]['Title'], $exportedRows[0]['Title']);
				}
			}

			// Verify Hashmap generation produces expected entry
			$hashcodes = $this->exporter->generateHashcodes($library);
			self::assertStringContainsString('Bump City.pdf', $hashcodes);
			self::assertStringContainsString('12345678', $hashcodes);
			self::assertStringContainsString('1048576', $hashcodes);
		} finally {
			if (file_exists($exportedDbPath)) {
				@unlink($exportedDbPath);
			}
		}
	}

	public function testImportDatabaseLogsWarningWhenClientFilesTableContainsUnknownColumns(): void {
		$library = $this->createTestLibrary('Noten', 'admin');

		$db = $this->createTestDatabase();
		// Add unknown columns to Files table (e.g. from a future MobileSheets release)
		$db->exec('ALTER TABLE Files ADD COLUMN CropMargins TEXT');
		$db->exec('ALTER TABLE Files ADD COLUMN Rotation INTEGER');

		$warnings = [];
		$logger = new class($warnings) extends \Psr\Log\AbstractLogger {
			/** @param list<string> $warnings */
			public function __construct(
				private array &$warnings,
			) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				if ($level === \Psr\Log\LogLevel::WARNING) {
					$this->warnings[] = (string)$message;
				}
			}
		};

		$importer = new MobileSheetsSqliteImporter(
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
		);

		$importer->importFromFile($library, $this->tempDbPath);

		$warningFound = false;
		foreach ($warnings as $w) {
			if (str_contains($w, 'unknown column(s) in Files table') && str_contains($w, 'cropmargins') && str_contains($w, 'rotation')) {
				$warningFound = true;
				break;
			}
		}

		self::assertTrue($warningFound, 'Importer must log a warning with unknown column names');
	}

	public function testImportCreatesPlaceholderFileWhenMissingInLibrary(): void {
		$library = $this->createTestLibrary('Noten', 'admin');

		$db = $this->createTestDatabase();
		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (10, 'Test Song', 'Test Song', 0, 0, 0, '', '', '', 10, 1000, 1000)");
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES (20, 10, '/remote.php/dav/files/admin/Noten/Folder/Song.pdf', '1', 1024, 1000, 1, 1, 1, 12345)");

		$this->importer->importFromFile($library, $this->tempDbPath);

		$userFolder = $this->rootFolder->getUserFolder('admin');
		self::assertTrue($userFolder->nodeExists('Noten/Folder/Song.pdf'));

		$files = $this->songFileMapper->findAllForLibrary((int)$library->id, false);
		self::assertCount(1, $files);
		self::assertSame(20, $files[0]->clientId);
	}

	public function testImportIgnoresChildMovementSongsAndFiles(): void {
		$library = $this->createTestLibrary('/MovementsImportLib', 'admin');

		// Create physical Sonata.pdf in the library folder so pathResolver and userFolder resolve it for real
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('MovementsImportLib')) {
			$userFolder->newFolder('MovementsImportLib');
		}
		$libFolder = $userFolder->get('MovementsImportLib');
		$libFolder->newFile('Sonata.pdf', "%PDF-1.4 dummy\n%%EOF");

		$db = $this->createTestDatabase();
		$db->exec('CREATE TABLE Collections (Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE CollectionSong (Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE _nc_sync_metadata (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER NOT NULL, extra_json TEXT)');

		// Insert master song (id 10) and child movement song (id 11)
		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (10, 'Moonlight Sonata', 'Moonlight Sonata', 0, 0, 0, '', '', '', 10, 1000, 2000)");
		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (11, 'Moonlight Sonata - I. Adagio', 'Moonlight Sonata - I. Adagio', 0, 0, 0, '', '', '', 11, 1000, 2000)");

		// Mark song 11 as song_movement
		$db->exec("INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES ('song_movement', 'bm-uuid-1', 11, 2000, '{\"masterSongId\":10,\"startPage\":1,\"endPage\":4}')");

		// Insert movement collection (id 3)
		$db->exec("INSERT INTO Collections (Id, Name) VALUES (3, 'Moonlight Sonata')");
		$db->exec("INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES ('movement_collection', 'sonata-uuid:movement_collection', 3, 2000, '{\"parent_uuid\":\"sonata-uuid\"}')");
		$db->exec('INSERT INTO CollectionSong (CollectionId, SongId) VALUES (3, 11)');

		// Files for master and child
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES (100, 10, '/remote.php/dav/files/admin/MovementsImportLib/Sonata.pdf', '1-15', 204800, 2000, 1, 1, 15, 111)");
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES (101, 11, '/remote.php/dav/files/admin/MovementsImportLib/Sonata.pdf', '1-4', 204800, 2000, 1, 1, 15, 111)");

		// Run REAL importer with real Nextcloud database and real files
		$this->importer->importFromFile($library, $this->tempDbPath);

		// Verify only 1 song created in Nextcloud (the master song), child movement song was skipped
		$createdSongs = $this->songMapper->findAllForLibrary((int)$library->id);
		self::assertCount(1, $createdSongs);
		self::assertSame('Moonlight Sonata', $createdSongs[0]->title);
		self::assertSame(10, $createdSongs[0]->clientId);

		// Verify only 1 file created in Nextcloud, child movement file was skipped
		$createdFiles = $this->songFileMapper->findAllForLibrary((int)$library->id);
		self::assertCount(1, $createdFiles);
		self::assertSame(100, $createdFiles[0]->clientId);

		// Verify movement collection was NOT imported as a new user collection in Nextcloud
		$createdCollections = $this->entityMapper->findAllForLibrary((int)$library->id);
		self::assertCount(0, $createdCollections);
	}
}
