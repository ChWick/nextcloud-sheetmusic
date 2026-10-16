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
use OCA\SheetMusic\Service\MobileSheetsPathResolver;
use OCA\SheetMusic\Service\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Service\MobileSheetsSqliteImporter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MobileSheetsSyncTest extends TestCase {
	private \OCP\IDBConnection $dbConn;
	private InternalSongMapper $songMapper;
	private InternalEntityMapper $entityMapper;
	private InternalSongFileMapper $songFileMapper;
	private InternalSongAttributeMapper $attributeMapper;
	private InternalMiscMapper $miscMapper;
	private LibraryMapper $libraryMapper;
	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private MobileSheetsPathResolver $pathResolver;
	private MobileSheetsSqliteImporter $importer;
	private MobileSheetsSqliteExporter $exporter;

	/** @var list<int> */
	private array $createdLibraryIds = [];

	/** @var list<string> */
	private array $tempFiles = [];

	/** @var list<Folder|File> */
	private array $createdUserNodes = [];

	protected function setUp(): void {
		parent::setUp();

		$this->dbConn = \OC::$server->get(\OCP\IDBConnection::class);
		$this->songMapper = new InternalSongMapper($this->dbConn);
		$this->entityMapper = new InternalEntityMapper($this->dbConn);
		$this->songFileMapper = new InternalSongFileMapper($this->dbConn);
		$this->attributeMapper = new InternalSongAttributeMapper($this->dbConn);
		$this->miscMapper = new InternalMiscMapper($this->dbConn);
		$this->libraryMapper = new LibraryMapper($this->dbConn);
		$this->rootFolder = \OC::$server->get(IRootFolder::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$logger = new NullLogger();
		$this->pathResolver = new MobileSheetsPathResolver($this->rootFolder);

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
		);

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
		);
	}

	protected function tearDown(): void {
		foreach ($this->createdLibraryIds as $libId) {
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_entities WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$libId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_attributes WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$libId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_files WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$libId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_songs WHERE library_id = ?', [$libId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_entities WHERE library_id = ?', [$libId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_misc_data WHERE library_id = ?', [$libId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_misc_schema WHERE library_id = ?', [$libId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_libraries WHERE id = ?', [$libId]);
		}

		foreach ($this->createdUserNodes as $node) {
			try {
				if ($node instanceof File || $node instanceof Folder) {
					$node->delete();
				}
			} catch (\Throwable) {
				// Ignore cleanup errors
			}
		}

		foreach ($this->tempFiles as $file) {
			if (file_exists($file)) {
				@unlink($file);
			}
		}

		parent::tearDown();
	}

	private function createTestLibrary(string $path = 'SheetMusic'): InternalLibrary {
		$userId = 'sync_user_' . bin2hex(random_bytes(4));
		$this->libraryMapper->addForUser($userId, $path);
		$libs = $this->libraryMapper->findAllForUser($userId);
		$lib = $this->libraryMapper->findModelById((int)$libs[0]['id']);
		self::assertNotNull($lib);
		$this->createdLibraryIds[] = (int)$lib->id;
		return $lib;
	}

	private function tempDbPath(): string {
		$path = tempnam(sys_get_temp_dir(), 'ms_sync_') . '.db';
		$this->tempFiles[] = $path;
		return $path;
	}

	private function createEmptyMobileSheetsDb(string $path): PDO {
		if (file_exists($path)) {
			@unlink($path);
		}
		$db = new PDO('sqlite:' . $path, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE SongNotes(Id INTEGER PRIMARY KEY, SongId INTEGER, Note TEXT, Title VARCHAR(255) DEFAULT \'\', DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');

		// Entity tables
		$db->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Artists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE ArtistsSongs(Id INTEGER PRIMARY KEY, ArtistId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Books(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY, BookId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Genres(Id INTEGER PRIMARY KEY, Type VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE GenresSongs(Id INTEGER PRIMARY KEY, GenreId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Key(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE Keys(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE CustomGroup(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE CustomGroupSongs(Id INTEGER PRIMARY KEY, GroupId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Years(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE YearsSongs(Id INTEGER PRIMARY KEY, YearId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Signature(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE SignatureSongs(Id INTEGER PRIMARY KEY, SignatureId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE SourceType(Id INTEGER PRIMARY KEY, Type VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE SourceTypes(Id INTEGER PRIMARY KEY, Type VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE SourceTypeSongs(Id INTEGER PRIMARY KEY, SourceTypeId INTEGER, SongId INTEGER)');

		// Misc tables
		$db->exec('CREATE TABLE Bookmarks(Id INTEGER PRIMARY KEY, SongId INTEGER, Name VARCHAR(255), PageNum INTEGER, ShowInLibrary INTEGER DEFAULT 0, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE Setlists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE MetronomeSettings(Id INTEGER PRIMARY KEY, SongId INTEGER, Bpm INTEGER, Sig1 INTEGER DEFAULT 4, Sig2 INTEGER DEFAULT 4)');

		// Metadata
		$db->exec('CREATE TABLE _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))');

		return $db;
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function entityTableProvider(): array {
		return [
			'Composer' => ['Composer', 'composer', 'Name'],
			'Artists' => ['Artists', 'artist', 'Name'],
			'Books' => ['Books', 'book', 'Title'],
			'Collections' => ['Collections', 'collection', 'Name'],
			'Genres' => ['Genres', 'genre', 'Type'],
			'Key (singular)' => ['Key', 'key', 'Name'],
			'Keys (plural)' => ['Keys', 'key', 'Name'],
			'CustomGroup' => ['CustomGroup', 'customgroup', 'Name'],
			'Years' => ['Years', 'year', 'Name'],
			'Signature' => ['Signature', 'signature', 'Name'],
			'SourceType (singular)' => ['SourceType', 'sourcetype', 'Type'],
			'SourceTypes (plural)' => ['SourceTypes', 'sourcetype', 'Type'],
		];
	}

	/**
	 * @dataProvider entityTableProvider
	 */
	public function testEntitySingleAdd(string $tableName, string $type, string $nameCol): void {
		$lib = $this->createTestLibrary();
		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// Insert 1 entity in MobileSheets DB
		$msDb->exec(sprintf(
			'INSERT INTO "%s" (Id, "%s", SortBy, Ascending, DateCreated, LastModified) VALUES (10, \'Test Item\', 2, 0, 1000000, 1700000000000)',
			$tableName,
			$nameCol,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(1, $stats['entitiesCreated']);
		self::assertSame(0, $stats['entitiesUpdated']);
		self::assertSame(0, $stats['entitiesDeleted']);

		$entities = $this->entityMapper->findAllForLibrary($lib->id, $type);
		self::assertCount(1, $entities);
		self::assertSame('Test Item', $entities[0]->name);
		self::assertSame(10, $entities[0]->clientId);
		self::assertSame(2, $entities[0]->sortBy);
		self::assertSame(0, $entities[0]->ascending);
		self::assertSame(1700000000, $entities[0]->updatedAt);
	}

	/**
	 * @dataProvider entityTableProvider
	 */
	public function testEntityConflictResolutionMobileSheetsWins(string $tableName, string $type, string $nameCol): void {
		$lib = $this->createTestLibrary();

		// Create existing entity in Nextcloud modified at timestamp 1000
		$entity = new InternalEntity(
			uuid: 'ent-uuid-' . bin2hex(random_bytes(4)),
			libraryId: $lib->id,
			type: $type,
			name: 'Original NC Name',
			sortBy: 1,
			ascending: 1,
			clientId: 10,
			createdAt: 500,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($entity);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets has newer LastModified: 2000s (2000000 ms) > 1000s
		$msDb->exec(sprintf(
			'INSERT INTO "%s" (Id, "%s", SortBy, Ascending, DateCreated, LastModified) VALUES (10, \'Updated by MobileSheets\', 3, 0, 500000, 2000000)',
			$tableName,
			$nameCol,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['entitiesCreated']);
		self::assertSame(1, $stats['entitiesUpdated']);

		$updated = $this->entityMapper->findById($entity->id);
		self::assertNotNull($updated);
		self::assertSame('Updated by MobileSheets', $updated->name);
		self::assertSame(3, $updated->sortBy);
		self::assertSame(0, $updated->ascending);
		self::assertSame(2000, $updated->updatedAt);
	}

	/**
	 * @dataProvider entityTableProvider
	 */
	public function testEntityConflictResolutionNextcloudWins(string $tableName, string $type, string $nameCol): void {
		$lib = $this->createTestLibrary();

		// Nextcloud entity is newer: updatedAt = 3000
		$entity = new InternalEntity(
			uuid: 'ent-uuid-' . bin2hex(random_bytes(4)),
			libraryId: $lib->id,
			type: $type,
			name: 'Nextcloud Newer Name',
			sortBy: 1,
			ascending: 1,
			clientId: 10,
			createdAt: 500,
			updatedAt: 3000,
		);
		$this->entityMapper->insert($entity);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets has older LastModified: 1000s (1000000 ms) <= 3000s
		$msDb->exec(sprintf(
			'INSERT INTO "%s" (Id, "%s", SortBy, Ascending, DateCreated, LastModified) VALUES (10, \'Stale MS Name\', 3, 0, 500000, 1000000)',
			$tableName,
			$nameCol,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['entitiesCreated']);
		self::assertSame(0, $stats['entitiesUpdated']);

		$reloaded = $this->entityMapper->findById($entity->id);
		self::assertNotNull($reloaded);
		self::assertSame('Nextcloud Newer Name', $reloaded->name);
		self::assertSame(1, $reloaded->sortBy);
		self::assertSame(3000, $reloaded->updatedAt);
	}

	/**
	 * @dataProvider entityTableProvider
	 */
	public function testEntityTombstoneRevival(string $tableName, string $type, string $nameCol): void {
		$lib = $this->createTestLibrary();

		// Entity tombstoned in Nextcloud at 1500
		$entity = new InternalEntity(
			uuid: 'ent-uuid-' . bin2hex(random_bytes(4)),
			libraryId: $lib->id,
			type: $type,
			name: 'Deleted Entity',
			sortBy: 1,
			ascending: 1,
			clientId: 10,
			createdAt: 500,
			updatedAt: 1500,
			deletedAt: 1500,
		);
		$this->entityMapper->insert($entity);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets edited after tombstone: LastModified 2500s > 1500s
		$msDb->exec(sprintf(
			'INSERT INTO "%s" (Id, "%s", SortBy, Ascending, DateCreated, LastModified) VALUES (10, \'Revived Entity\', 1, 1, 500000, 2500000)',
			$tableName,
			$nameCol,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['entitiesCreated']);
		self::assertSame(1, $stats['entitiesUpdated']);

		$revived = $this->entityMapper->findById($entity->id);
		self::assertNotNull($revived);
		self::assertNull($revived->deletedAt, 'Entity should be revived (deletedAt = null)');
		self::assertSame('Revived Entity', $revived->name);
		self::assertSame(2500, $revived->updatedAt);
	}

	/**
	 * @dataProvider entityTableProvider
	 */
	public function testEntityTombstoneRemainsDeletedWhenMobileSheetsIsOlder(string $tableName, string $type, string $nameCol): void {
		$lib = $this->createTestLibrary();

		// Entity tombstoned in Nextcloud at 2000
		$entity = new InternalEntity(
			uuid: 'ent-uuid-' . bin2hex(random_bytes(4)),
			libraryId: $lib->id,
			type: $type,
			name: 'Deleted Entity',
			sortBy: 1,
			ascending: 1,
			clientId: 10,
			createdAt: 500,
			updatedAt: 2000,
			deletedAt: 2000,
		);
		$this->entityMapper->insert($entity);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets has LastModified 1000s <= 2000s
		$msDb->exec(sprintf(
			'INSERT INTO "%s" (Id, "%s", SortBy, Ascending, DateCreated, LastModified) VALUES (10, \'Stale Entity\', 1, 1, 500000, 1000000)',
			$tableName,
			$nameCol,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['entitiesCreated']);
		self::assertSame(0, $stats['entitiesUpdated']);

		$reloaded = $this->entityMapper->findById($entity->id);
		self::assertNotNull($reloaded);
		self::assertSame(2000, $reloaded->deletedAt, 'Entity should remain deleted');
	}

	/**
	 * @dataProvider entityTableProvider
	 */
	public function testEntityRemovalByMobileSheets(string $tableName, string $type, string $nameCol): void {
		$lib = $this->createTestLibrary();

		$uuid = 'ent-uuid-' . bin2hex(random_bytes(4));
		$entity = new InternalEntity(
			uuid: $uuid,
			libraryId: $lib->id,
			type: $type,
			name: 'Entity To Be Removed',
			sortBy: 1,
			ascending: 1,
			clientId: 15,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($entity);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// Metadata contains entity, but table does NOT contain row 15
		$msDb->exec(sprintf(
			'INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (\'%s\', \'%s\', 15, 1000, \'{}\')',
			'entity:' . $type,
			$uuid,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(1, $stats['entitiesDeleted']);

		$deleted = $this->entityMapper->findById($entity->id);
		self::assertNotNull($deleted);
		self::assertNotNull($deleted->deletedAt, 'Entity should be tombstoned');
	}

	public function testSongSingleAdd(): void {
		$lib = $this->createTestLibrary();
		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		$msDb->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (101, 'Moonlight Sonata', 'Moonlight Sonata', 5, 4, 360, 'piano, beethoven', 'Classical', 'Solo', 101, 1000000, 1700000000000)");
		$msDb->exec("INSERT INTO SongNotes (SongId, Note, Title, DateCreated, LastModified) VALUES (101, 'Adagio sostenuto', '', 1000000, 1700000000000)");

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(1, $stats['songsCreated']);
		self::assertSame(0, $stats['songsUpdated']);
		self::assertSame(0, $stats['songsDeleted']);

		$songs = $this->songMapper->findByLibrary($lib->id);
		self::assertCount(1, $songs);
		$song = $songs[0];
		self::assertSame('Moonlight Sonata', $song->title);
		self::assertSame(5, $song->rating);
		self::assertSame(4, $song->difficulty);
		self::assertSame(360, $song->duration);
		self::assertSame('piano, beethoven', $song->keywords);
		self::assertSame('Adagio sostenuto', $song->notes);
		self::assertSame(101, $song->clientId);
		self::assertSame(1700000000, $song->updatedAt);

		$attrs = $this->attributeMapper->findAllForLibrary($lib->id);
		self::assertSame('Classical', $attrs[$song->id]['custom'] ?? null);
		self::assertSame('Solo', $attrs[$song->id]['custom2'] ?? null);
	}

	public function testSongConflictResolutionMobileSheetsWins(): void {
		$lib = $this->createTestLibrary();

		$song = new InternalSong(
			uuid: 'song-uuid-1',
			libraryId: $lib->id,
			title: 'Old Title',
			sortTitle: 'Old Title',
			rating: 1,
			difficulty: 1,
			duration: 100,
			keywords: 'old',
			notes: 'old note',
			clientId: 101,
			createdAt: 500,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets newer: LastModified 2000s > 1000s
		$msDb->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (101, 'New Title MS', 'New Title MS', 5, 3, 250, 'new, jazz', 'Bebop', '', 101, 500000, 2000000)");
		$msDb->exec("INSERT INTO SongNotes (SongId, Note, DateCreated, LastModified) VALUES (101, 'Updated Note', 500000, 2000000)");

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['songsCreated']);
		self::assertSame(1, $stats['songsUpdated']);

		$updated = $this->songMapper->findById($song->id);
		self::assertNotNull($updated);
		self::assertSame('New Title MS', $updated->title);
		self::assertSame(5, $updated->rating);
		self::assertSame(3, $updated->difficulty);
		self::assertSame(250, $updated->duration);
		self::assertSame('new, jazz', $updated->keywords);
		self::assertSame('Updated Note', $updated->notes);
		self::assertSame(2000, $updated->updatedAt);

		$attrs = $this->attributeMapper->findAllForLibrary($lib->id);
		self::assertSame('Bebop', $attrs[$song->id]['custom'] ?? null);
	}

	public function testSongConflictResolutionNextcloudWins(): void {
		$lib = $this->createTestLibrary();

		$song = new InternalSong(
			uuid: 'song-uuid-2',
			libraryId: $lib->id,
			title: 'Nextcloud Newer Title',
			sortTitle: 'Nextcloud Newer Title',
			rating: 5,
			difficulty: 5,
			duration: 300,
			keywords: 'nc',
			notes: 'nc note',
			clientId: 101,
			createdAt: 500,
			updatedAt: 3000,
		);
		$this->songMapper->insert($song);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets older: LastModified 1000s <= 3000s
		$msDb->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (101, 'Stale MS Title', 'Stale MS Title', 1, 1, 100, 'stale', '', '', 101, 500000, 1000000)");

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['songsCreated']);
		self::assertSame(0, $stats['songsUpdated']);

		$reloaded = $this->songMapper->findById($song->id);
		self::assertNotNull($reloaded);
		self::assertSame('Nextcloud Newer Title', $reloaded->title);
		self::assertSame(5, $reloaded->rating);
		self::assertSame(3000, $reloaded->updatedAt);
	}

	public function testSongTombstoneRevival(): void {
		$lib = $this->createTestLibrary();

		$song = new InternalSong(
			uuid: 'song-uuid-3',
			libraryId: $lib->id,
			title: 'Deleted Song',
			sortTitle: 'Deleted Song',
			rating: 1,
			difficulty: 1,
			duration: 100,
			keywords: '',
			notes: '',
			clientId: 101,
			createdAt: 500,
			updatedAt: 1500,
			deletedAt: 1500,
		);
		$this->songMapper->insert($song);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets edited after deletion: LastModified 2500s > 1500s
		$msDb->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (101, 'Revived Song', 'Revived Song', 4, 2, 200, 'revived', '', '', 101, 500000, 2500000)");

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['songsCreated']);
		self::assertSame(1, $stats['songsUpdated']);

		$revived = $this->songMapper->findById($song->id);
		self::assertNotNull($revived);
		self::assertNull($revived->deletedAt, 'Song should be revived');
		self::assertSame('Revived Song', $revived->title);
		self::assertSame(2500, $revived->updatedAt);
	}

	public function testSongTombstoneRemainsDeletedWhenMobileSheetsIsOlder(): void {
		$lib = $this->createTestLibrary();

		$song = new InternalSong(
			uuid: 'song-uuid-4',
			libraryId: $lib->id,
			title: 'Deleted Song',
			sortTitle: 'Deleted Song',
			rating: 1,
			difficulty: 1,
			duration: 100,
			keywords: '',
			notes: '',
			clientId: 101,
			createdAt: 500,
			updatedAt: 2000,
			deletedAt: 2000,
		);
		$this->songMapper->insert($song);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets older: LastModified 1000s <= 2000s
		$msDb->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified) VALUES (101, 'Stale Song', 'Stale Song', 1, 1, 100, '', '', '', 101, 500000, 1000000)");

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['songsCreated']);
		self::assertSame(0, $stats['songsUpdated']);

		$reloaded = $this->songMapper->findById($song->id);
		self::assertNotNull($reloaded);
		self::assertSame(2000, $reloaded->deletedAt, 'Song must remain deleted');
	}

	public function testSongRemovalByMobileSheets(): void {
		$lib = $this->createTestLibrary();

		$uuid = 'song-uuid-to-delete';
		$song = new InternalSong(
			uuid: $uuid,
			libraryId: $lib->id,
			title: 'Song To Delete',
			sortTitle: 'Song To Delete',
			rating: 3,
			difficulty: 2,
			duration: 180,
			keywords: '',
			notes: '',
			clientId: 101,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// Sync metadata contains song, but Songs table is empty
		$msDb->exec(sprintf(
			'INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (\'song\', \'%s\', 101, 1000, \'{}\')',
			$uuid,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(1, $stats['songsDeleted']);

		$deleted = $this->songMapper->findById($song->id);
		self::assertNotNull($deleted);
		self::assertNotNull($deleted->deletedAt, 'Song should be tombstoned');
	}

	/**
	 * @return array<string, array{string, string, string, string, string}>
	 */
	public static function linkTableProvider(): array {
		return [
			'Composer' => ['ComposerSongs', 'ComposerId', 'Composer', 'composer', 'Name'],
			'Artists' => ['ArtistsSongs', 'ArtistId', 'Artists', 'artist', 'Name'],
			'Books' => ['BookSongs', 'BookId', 'Books', 'book', 'Title'],
			'Collections' => ['CollectionSong', 'CollectionId', 'Collections', 'collection', 'Name'],
			'Genres' => ['GenresSongs', 'GenreId', 'Genres', 'genre', 'Type'],
			'Key' => ['KeySongs', 'KeyId', 'Key', 'key', 'Name'],
			'CustomGroup' => ['CustomGroupSongs', 'GroupId', 'CustomGroup', 'customgroup', 'Name'],
			'Years' => ['YearsSongs', 'YearId', 'Years', 'year', 'Name'],
			'Signature' => ['SignatureSongs', 'SignatureId', 'Signature', 'signature', 'Name'],
			'SourceType' => ['SourceTypeSongs', 'SourceTypeId', 'SourceType', 'sourcetype', 'Type'],
		];
	}

	/**
	 * @dataProvider linkTableProvider
	 */
	public function testSongEntityLinkAddRemoveAndChange(
		string $linkTable,
		string $linkCol,
		string $entityTable,
		string $entityType,
		string $nameCol,
	): void {
		$lib = $this->createTestLibrary();

		// 1. Create a song in Nextcloud
		$song = new InternalSong(
			uuid: 'song-link-uuid',
			libraryId: $lib->id,
			title: 'Link Test Song',
			sortTitle: 'Link Test Song',
			rating: 3,
			difficulty: 2,
			duration: 200,
			keywords: '',
			notes: '',
			clientId: 100,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song);

		// 2. Create two entities in Nextcloud
		$entity1 = new InternalEntity(
			uuid: 'ent-1-uuid',
			libraryId: $lib->id,
			type: $entityType,
			name: 'Entity One',
			sortBy: 1,
			ascending: 1,
			clientId: 10,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($entity1);

		$entity2 = new InternalEntity(
			uuid: 'ent-2-uuid',
			libraryId: $lib->id,
			type: $entityType,
			name: 'Entity Two',
			sortBy: 1,
			ascending: 1,
			clientId: 20,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($entity2);

		// STEP A: MobileSheets adds a link from Song to Entity 1
		$dbPath1 = $this->tempDbPath();
		$msDb1 = $this->createEmptyMobileSheetsDb($dbPath1);
		$msDb1->exec("INSERT INTO Songs (Id, Title, SortTitle, DateCreated, LastModified) VALUES (100, 'Link Test Song', 'Link Test Song', 1000000, 1500000)");
		$msDb1->exec(sprintf("INSERT INTO \"%s\" (Id, \"%s\", DateCreated, LastModified) VALUES (10, 'Entity One', 1000000, 1000000)", $entityTable, $nameCol));
		$msDb1->exec(sprintf("INSERT INTO \"%s\" (Id, \"%s\", DateCreated, LastModified) VALUES (20, 'Entity Two', 1000000, 1000000)", $entityTable, $nameCol));
		$msDb1->exec(sprintf('INSERT INTO "%s" ("%s", SongId) VALUES (10, 100)', $linkTable, $linkCol));

		$this->importer->importFromFile($lib, $dbPath1);

		$links1 = $this->entityMapper->findAllSongEntityLinksForLibrary($lib->id);
		$matchingLinks1 = array_filter($links1, fn($l) => $l['song_id'] === $song->id && $l['type'] === $entityType);
		self::assertCount(1, $matchingLinks1);
		self::assertSame($entity1->id, (int)array_values($matchingLinks1)[0]['entity_id']);

		// STEP B: MobileSheets CHANGES the link from Entity 1 to Entity 2
		$dbPath2 = $this->tempDbPath();
		$msDb2 = $this->createEmptyMobileSheetsDb($dbPath2);
		// Bump song LastModified so it registers as updated
		$msDb2->exec("INSERT INTO Songs (Id, Title, SortTitle, DateCreated, LastModified) VALUES (100, 'Link Test Song', 'Link Test Song', 1000000, 2000000)");
		$msDb2->exec(sprintf("INSERT INTO \"%s\" (Id, \"%s\", DateCreated, LastModified) VALUES (10, 'Entity One', 1000000, 1000000)", $entityTable, $nameCol));
		$msDb2->exec(sprintf("INSERT INTO \"%s\" (Id, \"%s\", DateCreated, LastModified) VALUES (20, 'Entity Two', 1000000, 1000000)", $entityTable, $nameCol));
		$msDb2->exec(sprintf('INSERT INTO "%s" ("%s", SongId) VALUES (20, 100)', $linkTable, $linkCol));

		$this->importer->importFromFile($lib, $dbPath2);

		$links2 = $this->entityMapper->findAllSongEntityLinksForLibrary($lib->id);
		$matchingLinks2 = array_filter($links2, fn($l) => $l['song_id'] === $song->id && $l['type'] === $entityType);
		self::assertCount(1, $matchingLinks2);
		self::assertSame($entity2->id, (int)array_values($matchingLinks2)[0]['entity_id'], 'Link should now point to entity2');

		// STEP C: MobileSheets REMOVES all links for this song
		$dbPath3 = $this->tempDbPath();
		$msDb3 = $this->createEmptyMobileSheetsDb($dbPath3);
		$msDb3->exec("INSERT INTO Songs (Id, Title, SortTitle, DateCreated, LastModified) VALUES (100, 'Link Test Song', 'Link Test Song', 1000000, 2500000)");
		$msDb3->exec(sprintf("INSERT INTO \"%s\" (Id, \"%s\", DateCreated, LastModified) VALUES (10, 'Entity One', 1000000, 1000000)", $entityTable, $nameCol));
		$msDb3->exec(sprintf("INSERT INTO \"%s\" (Id, \"%s\", DateCreated, LastModified) VALUES (20, 'Entity Two', 1000000, 1000000)", $entityTable, $nameCol));
		// No rows in linkTable

		$this->importer->importFromFile($lib, $dbPath3);

		$links3 = $this->entityMapper->findAllSongEntityLinksForLibrary($lib->id);
		$matchingLinks3 = array_filter($links3, fn($l) => $l['song_id'] === $song->id && $l['type'] === $entityType);
		self::assertCount(0, $matchingLinks3, 'All links for this song and type should be removed');
	}

	public function testFileAddConflictAndRemovalWithRealUserFile(): void {
		$lib = $this->createTestLibrary('NotenFolder');

		// 1. Create a real PDF file in the user folder (admin)
		$adminUser = $this->userManager->get('admin');
		self::assertNotNull($adminUser);
		$userFolder = $this->rootFolder->getUserFolder('admin');

		$testSubfolderName = 'TestSyncMusic_' . bin2hex(random_bytes(3));
		$testFolder = $userFolder->newFolder($testSubfolderName);
		$this->createdUserNodes[] = $testFolder;

		$testFile = $testFolder->newFile('sonata.pdf');
		$testFile->putContent("%PDF-1.4 test score content\n");
		$this->createdUserNodes[] = $testFile;
		$realFileId = $testFile->getId();

		// 2. Create song in Nextcloud
		$song = new InternalSong(
			uuid: 'song-file-uuid',
			libraryId: $lib->id,
			title: 'Sonata with File',
			sortTitle: 'Sonata with File',
			rating: 3,
			difficulty: 2,
			duration: 180,
			keywords: '',
			notes: '',
			clientId: 10,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song);

		// STEP A: MobileSheets adds file row referencing the WebDAV path
		$webdavPath = sprintf('/remote.php/dav/files/admin/%s/sonata.pdf', $testSubfolderName);
		$dbPath1 = $this->tempDbPath();
		$msDb1 = $this->createEmptyMobileSheetsDb($dbPath1);
		$msDb1->exec("INSERT INTO Songs (Id, Title, SortTitle, DateCreated, LastModified) VALUES (10, 'Sonata with File', 'Sonata with File', 1000000, 1000000)");
		$msDb1->exec(sprintf(
			"INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES (50, 10, '%s', '1-5', 2048, 1000000, 1, 1, 5, 123456)",
			$webdavPath,
		));

		$stats1 = $this->importer->importFromFile($lib, $dbPath1, $adminUser);
		self::assertSame(1, $stats1['filesCreated']);

		$files1 = $this->songFileMapper->findAllForSong($song->id);
		self::assertCount(1, $files1);
		self::assertSame($realFileId, $files1[0]->fileId, 'Should resolve to real Nextcloud fileId');
		self::assertSame('1-5', $files1[0]->pageOrder);
		self::assertSame(123456, $files1[0]->fileHash);
		self::assertSame(50, $files1[0]->clientId);

		// STEP B: MobileSheets updates file (newer LastModified: 2000s > 1000s) with different pageOrder
		$dbPath2 = $this->tempDbPath();
		$msDb2 = $this->createEmptyMobileSheetsDb($dbPath2);
		$msDb2->exec("INSERT INTO Songs (Id, Title, SortTitle, DateCreated, LastModified) VALUES (10, 'Sonata with File', 'Sonata with File', 1000000, 1000000)");
		$msDb2->exec(sprintf(
			"INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES (50, 10, '%s', '1-3,5', 2048, 2000000, 1, 1, 4, 789012)",
			$webdavPath,
		));

		$stats2 = $this->importer->importFromFile($lib, $dbPath2, $adminUser);
		self::assertSame(1, $stats2['filesUpdated']);

		$files2 = $this->songFileMapper->findAllForSong($song->id);
		self::assertCount(1, $files2);
		self::assertSame('1-3,5', $files2[0]->pageOrder);
		self::assertSame(789012, $files2[0]->fileHash);
		self::assertSame(2000, $files2[0]->updatedAt);

		// STEP C: MobileSheets removes file (sync metadata has file, Files table is empty)
		$dbPath3 = $this->tempDbPath();
		$msDb3 = $this->createEmptyMobileSheetsDb($dbPath3);
		$msDb3->exec("INSERT INTO Songs (Id, Title, SortTitle, DateCreated, LastModified) VALUES (10, 'Sonata with File', 'Sonata with File', 1000000, 1000000)");
		$msDb3->exec(sprintf(
			"INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES ('file', '%s', 50, 2000, '{\"file_id\":%d}')",
			$files2[0]->uuid,
			$realFileId,
		));

		$stats3 = $this->importer->importFromFile($lib, $dbPath3, $adminUser);
		self::assertSame(1, $stats3['filesDeleted']);

		$files3 = $this->songFileMapper->findAllForSong($song->id);
		self::assertCount(0, $files3, 'Active files should be empty');
		$allFiles = $this->songFileMapper->findAllForLibrary($lib->id, includeDeleted: true);
		self::assertCount(1, $allFiles);
		self::assertNotNull($allFiles[0]->deletedAt, 'File should be tombstoned');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function miscTableProvider(): array {
		return [
			'Bookmarks' => ['Bookmarks'],
			'Setlists' => ['Setlists'],
			'MetronomeSettings' => ['MetronomeSettings'],
		];
	}

	/**
	 * @dataProvider miscTableProvider
	 */
	public function testMiscTableAddAndExport(string $tableName): void {
		$lib = $this->createTestLibrary();
		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		if ($tableName === 'Bookmarks') {
			$msDb->exec("INSERT INTO Bookmarks (Id, SongId, Name, PageNum, ShowInLibrary, DateCreated, LastModified) VALUES (1, 100, 'Trio Section', 3, 1, 1000000, 1700000000000)");
		} elseif ($tableName === 'Setlists') {
			$msDb->exec("INSERT INTO Setlists (Id, Name, DateCreated, LastModified) VALUES (1, 'Spring Concert 2026', 1000000, 1700000000000)");
		} elseif ($tableName === 'MetronomeSettings') {
			$msDb->exec("INSERT INTO MetronomeSettings (Id, SongId, Bpm, Sig1, Sig2) VALUES (1, 100, 132, 3, 4)");
		}

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(1, $stats['miscTablesSynced']);

		// Verify stored in internal Nextcloud misc data
		$rows = $this->miscMapper->getMiscDataForTable($lib->id, 'mobilesheets', $tableName);
		self::assertCount(1, $rows);

		// Export back to new SQLite DB
		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath);
		self::assertFileExists($exportPath);

		$expDb = new PDO('sqlite:' . $exportPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$expRows = $expDb->query(sprintf('SELECT * FROM "%s"', $tableName))->fetchAll();
		self::assertCount(1, $expRows);

		if ($tableName === 'Bookmarks') {
			self::assertSame('Trio Section', $expRows[0]['Name']);
			self::assertSame(3, (int)$expRows[0]['PageNum']);
		} elseif ($tableName === 'Setlists') {
			self::assertSame('Spring Concert 2026', $expRows[0]['Name']);
		} elseif ($tableName === 'MetronomeSettings') {
			self::assertSame(132, (int)$expRows[0]['Bpm']);
		}
	}

	public function testEndToEndBiDirectionalSyncCycle(): void {
		$lib = $this->createTestLibrary();

		// 1. Initial State in Nextcloud: Song A + Composer A
		$songA = new InternalSong(
			uuid: 'song-a-uuid',
			libraryId: $lib->id,
			title: 'Song A (NC Original)',
			sortTitle: 'Song A (NC Original)',
			rating: 2,
			difficulty: 1,
			duration: 120,
			keywords: 'classical',
			notes: 'Play gently',
			clientId: 1,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($songA);

		$composerA = new InternalEntity(
			uuid: 'comp-a-uuid',
			libraryId: $lib->id,
			type: 'composer',
			name: 'Composer A',
			sortBy: 1,
			ascending: 1,
			clientId: 10,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($composerA);
		$this->entityMapper->setSongEntitiesForType($songA->id, 'composer', [$composerA->id]);

		// 2. Nextcloud exports to SQLite (faked mobilesheets.db for WebDAV GET)
		$exportPath1 = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath1);
		self::assertFileExists($exportPath1);

		// 3. MobileSheets reads the DB, makes modifications:
		//    - Modifies Song A (rating -> 5, title -> 'Song A (Updated by MS)', LastModified -> 2000s)
		//    - Adds Song B (clientId 2, LastModified 2000s)
		//    - Deletes Composer A (removed from Composer table, but in _nc_sync_metadata)
		//    - Adds Composer B (clientId 20)
		//    - Links Song B to Composer B
		$msDb = new PDO('sqlite:' . $exportPath1, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$msDb->exec("UPDATE Songs SET Title = 'Song A (Updated by MS)', Stars = 5, LastModified = 2000000 WHERE Id = 1");
		$msDb->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, DateCreated, LastModified) VALUES (2, 'Song B (Added in MS)', 'Song B (Added in MS)', 4, 3, 200, 2000000, 2000000)");
		$msDb->exec("DELETE FROM Composer WHERE Id = 10");
		$msDb->exec("DELETE FROM ComposerSongs WHERE ComposerId = 10");
		$msDb->exec("INSERT INTO Composer (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES (20, 'Composer B', 1, 1, 2000000, 2000000)");
		$msDb->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (20, 2)");

		// 4. MobileSheets uploads DB back via WebDAV PUT (triggering importFromFile)
		$stats = $this->importer->importFromFile($lib, $exportPath1);

		self::assertSame(1, $stats['songsCreated'], 'Song B should be created');
		self::assertSame(1, $stats['songsUpdated'], 'Song A should be updated');
		self::assertSame(0, $stats['songsDeleted']);
		self::assertSame(1, $stats['entitiesCreated'], 'Composer B should be created');
		self::assertSame(1, $stats['entitiesDeleted'], 'Composer A should be deleted');

		// 5. Verify Nextcloud internal database state
		$reloadedA = $this->songMapper->findById($songA->id);
		self::assertNotNull($reloadedA);
		self::assertSame('Song A (Updated by MS)', $reloadedA->title);
		self::assertSame(5, $reloadedA->rating);
		self::assertSame(2000, $reloadedA->updatedAt);

		$songs = $this->songMapper->findByLibrary($lib->id);
		self::assertCount(2, $songs, 'Both Song A and Song B should exist');

		$deletedCompA = $this->entityMapper->findById($composerA->id);
		self::assertNotNull($deletedCompA);
		self::assertNotNull($deletedCompA->deletedAt, 'Composer A should be tombstoned');

		$activeComposers = $this->entityMapper->findAllForLibrary($lib->id, 'composer');
		self::assertCount(1, $activeComposers);
		self::assertSame('Composer B', $activeComposers[0]->name);

		// 6. Nextcloud exports back out to SQLite
		$exportPath2 = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath2);
		self::assertFileExists($exportPath2);

		// 7. Verify exported database matches expected merged state
		$finalDb = new PDO('sqlite:' . $exportPath2, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$finalSongs = $finalDb->query('SELECT Id, Title, Stars FROM Songs ORDER BY Id ASC')->fetchAll();
		self::assertCount(2, $finalSongs);
		self::assertSame(1, (int)$finalSongs[0]['Id']);
		self::assertSame('Song A (Updated by MS)', $finalSongs[0]['Title']);
		self::assertSame(5, (int)$finalSongs[0]['Stars']);
		self::assertSame(2, (int)$finalSongs[1]['Id']);
		self::assertSame('Song B (Added in MS)', $finalSongs[1]['Title']);

		$finalComposers = $finalDb->query('SELECT Id, Name FROM Composer')->fetchAll();
		self::assertCount(1, $finalComposers);
		self::assertSame('Composer B', $finalComposers[0]['Name']);

		$finalLinks = $finalDb->query('SELECT ComposerId, SongId FROM ComposerSongs')->fetchAll();
		self::assertCount(1, $finalLinks);
		self::assertSame(20, (int)$finalLinks[0]['ComposerId']);
		self::assertSame(2, (int)$finalLinks[0]['SongId']);
	}
}

