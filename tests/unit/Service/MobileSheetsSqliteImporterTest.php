<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

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
use OCA\SheetMusic\Service\MobileSheetsPath;
use OCA\SheetMusic\Service\MobileSheetsPathResolver;
use OCA\SheetMusic\Service\MobileSheetsSqliteImporter;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
		$db->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (60, 50)");
		// Insert file
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES (70, 50, '/remote.php/dav/files/admin/Noten/Debussy.pdf', '1-3', 204800, 1700000000000, 1, 1, 3, 555666)");
		// Insert misc table Bookmarks
		$db->exec("CREATE TABLE Bookmarks (Id INTEGER PRIMARY KEY, SongId INTEGER, Name VARCHAR(255), PageNum INTEGER)");
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
		$logger = $this->createMock(LoggerInterface::class);

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
		$songMapper->method('findByLibrary')->willReturnCallback(function () use (&$createdSong) {
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
		$logger = $this->createMock(LoggerInterface::class);

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
		$logger = $this->createMock(LoggerInterface::class);

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
		$logger = $this->createMock(LoggerInterface::class);

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
		$logger = $this->createMock(LoggerInterface::class);

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
		self::assertSame(1, $deletedId);
	}
}
