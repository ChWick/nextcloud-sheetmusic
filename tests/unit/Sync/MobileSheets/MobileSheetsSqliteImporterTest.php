<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Db\SongEntityLink;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPath;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MobileSheetsSqliteImporterTest extends TestCase {
	private string $tempDbPath;

	protected function setUp(): void {
		parent::setUp();
		$this->tempDbPath = tempnam(sys_get_temp_dir(), 'sm_import_') . '.db';
	}

	protected function tearDown(): void {
		if (file_exists($this->tempDbPath)) {
			@unlink($this->tempDbPath);
		}
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

		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		// Entity resolution
		$entityMapper->method('findByClientId')->willReturn(null);
		$entityMapper->method('findByName')->willReturn(null);

		$createdEntity = null;
		$entityMapper->method('insert')->willReturnCallback(function (InternalEntity $e) use (&$createdEntity) {
			$e->id = 600;
			$createdEntity = $e;
			return $e;
		});

		// Song resolution
		$songMapper->method('findByClientId')->willReturn(null);
		$songMapper->method('findByTitle')->willReturn(null);

		$createdSong = null;
		$songMapper->method('insert')->willReturnCallback(function (InternalSong $s) use (&$createdSong) {
			$s->id = 500;
			$createdSong = $s;
			return $s;
		});

		// Links
		$songMapper->method('findAllForLibrary')->willReturnCallback(function () use (&$createdSong) {
			return $createdSong !== null ? [$createdSong] : [];
		});
		$entityMapper->method('findAllForLibrary')->willReturnCallback(function () use (&$createdEntity) {
			return $createdEntity !== null ? [$createdEntity] : [];
		});

		$linkedIds = [];
		$entityMapper->method('setSongEntitiesForType')->willReturnCallback(function ($songId, $type, $ids) use (&$linkedIds) {
			$linkedIds = $ids;
		});

		// File resolution
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(700);

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($node);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$msPath = new MobileSheetsPath(
			'/admin/files/Noten/Debussy.pdf',
			'/remote.php/dav/files/admin/Noten/Debussy.pdf',
		);
		$pathResolver->method('fromStored')->willReturn($msPath);
		$pathResolver->method('relativeToUserFolder')->willReturn('Noten/Debussy.pdf');

		$songFileMapper->method('findByClientId')->willReturn(null);
		$songFileMapper->method('findAllForSong')->willReturn([]);

		$createdFile = null;
		$songFileMapper->method('insert')->willReturnCallback(function (InternalSongFile $f) use (&$createdFile) {
			$f->id = 750;
			$createdFile = $f;
			return $f;
		});

		$importer = new MobileSheetsSqliteImporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$stats = $importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(1, $stats['songsCreated']);
		self::assertSame(1, $stats['entitiesCreated']);
		self::assertSame(1, $stats['filesCreated']);
		self::assertSame(1, $stats['miscTablesSynced']);

		// Verify created song
		self::assertNotNull($createdSong);
		self::assertSame('Clair de Lune', $createdSong->title);
		self::assertSame('Andante très expressif', $createdSong->notes);
		self::assertSame(4, $createdSong->rating);
		self::assertSame(50, $createdSong->clientId);

		// Verify created entity
		self::assertNotNull($createdEntity);
		self::assertSame('Claude Debussy', $createdEntity->name);
		self::assertSame('composer', $createdEntity->type);
		self::assertSame(60, $createdEntity->clientId);

		// Verify link
		self::assertSame([600], $linkedIds);

		// Verify created file
		self::assertNotNull($createdFile);
		self::assertSame(500, $createdFile->songId);
		self::assertSame(700, $createdFile->fileId);
		self::assertSame('1-3', $createdFile->pageOrder);
		self::assertSame(555666, $createdFile->fileHash);
		self::assertSame(70, $createdFile->clientId);
	}

	public function testLwwMergeMobileSheetsIsNewerUpdatesInternalSong(): void {
		$db = $this->createTestDatabase();
		// MobileSheets timestamp: 2000 in seconds = 2000000 ms
		$db->exec("INSERT INTO Songs (Id, Title, Stars, SongId, LastModified) VALUES (10, 'Updated Title', 5, 10, 2000000)");

		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		// Existing internal song with older updatedAt = 1000
		$existingSong = new InternalSong(
			id: 1,
			uuid: 'uuid-1',
			libraryId: 1,
			title: 'Old Title',
			rating: 3,
			clientId: 10,
			updatedAt: 1000,
		);
		$songMapper->method('findByClientId')->with(1, 10)->willReturn($existingSong);

		$updatedSong = null;
		$songMapper->method('update')->willReturnCallback(function (InternalSong $s) use (&$updatedSong) {
			$updatedSong = $s;
			return $s;
		});

		$importer = new MobileSheetsSqliteImporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$stats = $importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(1, $stats['songsUpdated']);
		self::assertNotNull($updatedSong);
		self::assertSame('Updated Title', $updatedSong->title);
		self::assertSame(5, $updatedSong->rating);
		self::assertSame(2000, $updatedSong->updatedAt);
	}

	public function testLwwMergeNextcloudIsNewerRetainsInternalSong(): void {
		$db = $this->createTestDatabase();
		// MobileSheets timestamp: 1000 in seconds = 1000000 ms
		$db->exec("INSERT INTO Songs (Id, Title, Stars, SongId, LastModified) VALUES (10, 'Client Title', 1, 10, 1000000)");

		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		// Existing internal song with newer updatedAt = 2000
		$existingSong = new InternalSong(
			id: 1,
			uuid: 'uuid-1',
			libraryId: 1,
			title: 'Nextcloud Title',
			rating: 5,
			clientId: 10,
			updatedAt: 2000,
		);
		$songMapper->method('findByClientId')->with(1, 10)->willReturn($existingSong);

		// update should not change title
		$songMapper->expects(self::never())->method('update');

		$importer = new MobileSheetsSqliteImporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$stats = $importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(0, $stats['songsUpdated']);
		self::assertSame('Nextcloud Title', $existingSong->title);
	}

	public function testTombstoneProtectionDeletedInNextcloudNotResurrectedByOlderClient(): void {
		$db = $this->createTestDatabase();
		// MobileSheets has LastModified 1000 in seconds = 1000000 ms
		$db->exec("INSERT INTO Songs (Id, Title, SongId, LastModified) VALUES (10, 'Old Song', 10, 1000000)");

		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		// Soft-deleted in Nextcloud at timestamp 2000 (after 1000)
		$existingSong = new InternalSong(
			id: 1,
			uuid: 'uuid-1',
			libraryId: 1,
			title: 'Old Song',
			clientId: 10,
			updatedAt: 1000,
			deletedAt: 2000,
		);
		$songMapper->method('findByClientId')->with(1, 10)->willReturn($existingSong);

		$songMapper->expects(self::never())->method('update');

		$importer = new MobileSheetsSqliteImporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$stats = $importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(0, $stats['songsUpdated']);
		self::assertSame(2000, $existingSong->deletedAt);
	}

	public function testDeletionsInMobileSheetsSoftDeletesInternalSong(): void {
		$db = $this->createTestDatabase();
		// Metadata recorded song with Id 10
		$db->exec('CREATE TABLE _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))');
		$db->exec("INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at) VALUES ('song', 'deleted-uuid', 10, 1000)");
		// But Songs table is EMPTY (user deleted song on MobileSheets!)

		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		$songToDelete = new InternalSong(
			id: 1,
			uuid: 'deleted-uuid',
			libraryId: 1,
			title: 'Song to Delete',
			clientId: 10,
			deletedAt: null,
		);
		$songMapper->method('findByUuid')->with('deleted-uuid')->willReturn($songToDelete);

		$attachedFile = new InternalSongFile(
			id: 50,
			uuid: 'attached-file-uuid',
			songId: 1,
			fileId: 777,
			clientId: 20,
			deletedAt: null,
		);
		$songFileMapper->method('findAllForSong')->with(1, false)->willReturn([$attachedFile]);
		$songFileMapper->method('findByFileId')->with(777, false)->willReturn([]);
		$songFileMapper->expects(self::once())->method('delete')->with(50, false);

		$physicalNode = $this->createMock(File::class);
		$physicalNode->expects(self::once())->method('delete');
		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getById')->with(777)->willReturn([$physicalNode]);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$deletedId = null;
		$songMapper->method('delete')->willReturnCallback(function ($id, $hardDelete) use (&$deletedId) {
			$deletedId = $id;
			self::assertFalse($hardDelete);
			return true;
		});

		$importer = new MobileSheetsSqliteImporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$stats = $importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(1, $stats['songsDeleted']);
		self::assertSame(1, $stats['filesDeleted']);
		self::assertSame(1, $deletedId);
	}

	public function testImportDatabasePreservesInstrumentEntityTypeAndLinks(): void {
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

		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		$createdEntities = [];
		$nextId = 100;
		$entityMapper->method('insert')->willReturnCallback(function (InternalEntity $e) use (&$createdEntities, &$nextId) {
			$e->id = $nextId++;
			$createdEntities[$e->clientId] = $e;
			return $e;
		});
		$entityMapper->method('findAllForLibrary')->willReturnCallback(function () use (&$createdEntities) {
			return array_values($createdEntities);
		});

		$song = new InternalSong(id: 1, uuid: 'song-uuid', libraryId: 1, title: 'Toccata', clientId: 1);
		$songMapper->method('findAllForLibrary')->willReturn([$song]);

		$setLinks = [];
		$entityMapper->method('setSongEntitiesForType')->willReturnCallback(function ($songId, $type, $ids) use (&$setLinks) {
			$setLinks[$type] = $ids;
		});

		$importer = new MobileSheetsSqliteImporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$stats = $importer->importFromFile($library, $this->tempDbPath);

		self::assertSame(2, $stats['entitiesCreated']);
		self::assertNotNull($createdEntities[10] ?? null);
		self::assertSame('instrument', $createdEntities[10]->type);
		self::assertSame('Organ', $createdEntities[10]->name);

		self::assertNotNull($createdEntities[20] ?? null);
		self::assertSame('customgroup', $createdEntities[20]->type);
		self::assertSame('Jazz Trio', $createdEntities[20]->name);

		// Assert song links were properly set for both types
		self::assertSame([100], $setLinks['instrument'] ?? []);
		self::assertSame([101], $setLinks['customgroup'] ?? []);
	}

	public function testImportAndExportProducesEquivalentDatabaseAndHashmap(): void {
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

		$storage = [
			'songs' => [],
			'entities' => [],
			'songFiles' => [],
			'songAttributes' => [],
			'songEntityLinks' => [],
			'miscSchemas' => [],
			'miscData' => [],
			'nextSongId' => 1,
			'nextEntityId' => 1,
			'nextFileId' => 1,
		];

		$songMapper = $this->createMock(InternalSongMapper::class);
		$songMapper->method('findAllForLibrary')->willReturnCallback(function (int $libId, bool $incDel = false) use (&$storage) {
			return array_values(array_filter($storage['songs'], fn ($x) => $x->libraryId === $libId && ($incDel || $x->deletedAt === null)));
		});
		$songMapper->method('findByClientId')->willReturnCallback(function (int $libId, int $cId) use (&$storage) {
			foreach ($storage['songs'] as $s) {
				if ($s->libraryId === $libId && $s->clientId === $cId) {
					return $s;
				}
			}
			return null;
		});
		$songMapper->method('findByUuid')->willReturnCallback(function (string $uuid) use (&$storage) {
			foreach ($storage['songs'] as $s) {
				if ($s->uuid === $uuid) {
					return $s;
				}
			}
			return null;
		});
		$songMapper->method('insert')->willReturnCallback(function (InternalSong $s) use (&$storage) {
			$s->id = $storage['nextSongId']++;
			$storage['songs'][$s->id] = $s;
			return $s;
		});
		$songMapper->method('update')->willReturnCallback(function (InternalSong $s) use (&$storage) {
			$storage['songs'][$s->id] = $s;
			return $s;
		});

		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$entityMapper->method('findAllForLibrary')->willReturnCallback(function (int $libId, ?string $type = null, bool $incDel = false) use (&$storage) {
			return array_values(array_filter($storage['entities'], fn ($x) => $x->libraryId === $libId && ($type === null || $x->type === $type) && ($incDel || $x->deletedAt === null)));
		});
		$entityMapper->method('findByClientId')->willReturnCallback(function (int $libId, string $type, int $cId) use (&$storage) {
			foreach ($storage['entities'] as $e) {
				if ($e->libraryId === $libId && $e->type === $type && $e->clientId === $cId) {
					return $e;
				}
			}
			return null;
		});
		$entityMapper->method('findByUuid')->willReturnCallback(function (string $uuid) use (&$storage) {
			foreach ($storage['entities'] as $e) {
				if ($e->uuid === $uuid) {
					return $e;
				}
			}
			return null;
		});
		$entityMapper->method('insert')->willReturnCallback(function (InternalEntity $e) use (&$storage) {
			$e->id = $storage['nextEntityId']++;
			$storage['entities'][$e->id] = $e;
			return $e;
		});
		$entityMapper->method('update')->willReturnCallback(function (InternalEntity $e) use (&$storage) {
			$storage['entities'][$e->id] = $e;
			return $e;
		});
		$entityMapper->method('setSongEntitiesForType')->willReturnCallback(function (int $sId, string $type, array $ids) use (&$storage) {
			$storage['songEntityLinks'] = array_filter($storage['songEntityLinks'], fn (SongEntityLink $l) => !($l->songId === $sId && $l->type === $type));
			foreach ($ids as $entId) {
				$storage['songEntityLinks'][] = new SongEntityLink(linkId: count($storage['songEntityLinks']) + 1, songId: $sId, entityId: $entId, type: $type);
			}
		});
		$entityMapper->method('findAllSongEntityLinksForLibrary')->willReturnCallback(function () use (&$storage) {
			return $storage['songEntityLinks'];
		});

		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$songFileMapper->method('findAllForLibrary')->willReturnCallback(function () use (&$storage) {
			return array_values($storage['songFiles']);
		});
		$songFileMapper->method('findAllForSong')->willReturnCallback(function (int $sId) use (&$storage) {
			return array_values(array_filter($storage['songFiles'], fn ($f) => $f->songId === $sId));
		});
		$songFileMapper->method('findByClientId')->willReturnCallback(function (int $sId, int $cId) use (&$storage) {
			foreach ($storage['songFiles'] as $f) {
				if ($f->songId === $sId && $f->clientId === $cId) {
					return $f;
				}
			}
			return null;
		});
		$songFileMapper->method('findByUuid')->willReturnCallback(function (string $uuid) use (&$storage) {
			foreach ($storage['songFiles'] as $f) {
				if ($f->uuid === $uuid) {
					return $f;
				}
			}
			return null;
		});
		$songFileMapper->method('insert')->willReturnCallback(function (InternalSongFile $f) use (&$storage) {
			$f->id = $storage['nextFileId']++;
			$storage['songFiles'][$f->id] = $f;
			return $f;
		});
		$songFileMapper->method('update')->willReturnCallback(function (InternalSongFile $f) use (&$storage) {
			$storage['songFiles'][$f->id] = $f;
			return $f;
		});

		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$attributeMapper->method('setAttribute')->willReturnCallback(function (int $sId, string $k, $v) use (&$storage) {
			$storage['songAttributes'][$sId][$k] = $v;
		});
		$attributeMapper->method('findAllForLibrary')->willReturnCallback(function () use (&$storage) {
			return $storage['songAttributes'];
		});

		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$miscMapper->method('saveMiscSchema')->willReturnCallback(function (int $lId, string $ct, string $tbl, string $sql) use (&$storage) {
			$storage['miscSchemas'][$tbl] = $sql;
		});
		$miscMapper->method('getMiscSchemas')->willReturnCallback(function () use (&$storage) {
			return $storage['miscSchemas'];
		});
		$miscMapper->method('saveMiscData')->willReturnCallback(function (int $lId, string $ct, string $tbl, string $recId, array $data) use (&$storage) {
			$storage['miscData'][$tbl][$recId] = $data;
		});
		$miscMapper->method('getMiscDataForTable')->willReturnCallback(function (int $lId, string $ct, string $tbl) use (&$storage) {
			return array_values($storage['miscData'][$tbl] ?? []);
		});

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$library = new InternalLibrary(id: 1, userId: 'admin', path: '/tmp/SheetMusic');

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(999);
		$node->method('getPath')->willReturn('/admin/files/tmp/SheetMusic/Bump City.pdf');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('getById')->willReturn([$node]);
		$userFolder->method('get')->willReturn($node);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$rootFolder->method('getById')->willReturn([$node]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$logger = new NullLogger();
		$pathResolver = new MobileSheetsPathResolver($rootFolder);

		// 1. Import from MobileSheets DB
		$importer = new MobileSheetsSqliteImporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$stats = $importer->importFromFile($library, $this->tempDbPath);
		self::assertSame(1, $stats['songsCreated']);
		self::assertSame(10, $stats['entitiesCreated']);
		self::assertSame(1, $stats['filesCreated']);

		// 2. Export back to SQLite DB
		$exporter = new MobileSheetsSqliteExporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$exportedDbPath = tempnam(sys_get_temp_dir(), 'exported_test_') . '.db';
		try {
			$exporter->exportToFile($library, $exportedDbPath);
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
			$hashcodes = $exporter->generateHashcodes($library);
			self::assertStringContainsString('/remote.php/dav/files/admin/tmp/SheetMusic/Bump City.pdf', $hashcodes);
			self::assertStringContainsString('12345678', $hashcodes);
			self::assertStringContainsString('1048576', $hashcodes);
		} finally {
			if (file_exists($exportedDbPath)) {
				@unlink($exportedDbPath);
			}
		}
	}
}
