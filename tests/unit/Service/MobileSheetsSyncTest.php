<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCP\Files\File;
use OCP\Files\Folder;
use PDO;
use PHPUnit\Framework\TestCase;

final class MobileSheetsSyncTest extends TestCase {
	use \TestSupport\RealMobileSheetsTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
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
			'Years' => ['Years', 'year', 'Year'],
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

	public function testMultipleSongsDeletionByMobileSheets(): void {
		$lib = $this->createTestLibrary();

		$songs = [];
		for ($i = 1; $i <= 3; $i++) {
			$s = new InternalSong(
				uuid: 'song-multi-' . $i . '-' . bin2hex(random_bytes(3)),
				libraryId: $lib->id,
				title: 'Multi Song ' . $i,
				sortTitle: 'Multi Song ' . $i,
				rating: $i,
				difficulty: 1,
				duration: 100,
				clientId: 100 + $i,
				createdAt: 1000,
				updatedAt: 1000,
			);
			$this->songMapper->insert($s);
			$songs[$i] = $s;
		}

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets has sync metadata for all 3 songs
		foreach ($songs as $i => $s) {
			$msDb->exec(sprintf(
				"INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES ('song', '%s', %d, 1000, '{}')",
				$s->uuid,
				100 + $i,
			));
		}

		// But Songs table only keeps Song 2 (Songs 1 and 3 were deleted in MobileSheets)
		$msDb->exec(sprintf(
			"INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, DateCreated, LastModified) VALUES (%d, '%s', '%s', 2, 1, 100, 1000000, 1000000)",
			102,
			$songs[2]->title,
			$songs[2]->sortTitle,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(2, $stats['songsDeleted']);
		self::assertSame(0, $stats['songsCreated']);

		$reloaded1 = $this->songMapper->findById($songs[1]->id);
		$reloaded2 = $this->songMapper->findById($songs[2]->id);
		$reloaded3 = $this->songMapper->findById($songs[3]->id);

		self::assertNotNull($reloaded1->deletedAt, 'Song 1 should be tombstoned');
		self::assertNull($reloaded2->deletedAt, 'Song 2 should remain active');
		self::assertNotNull($reloaded3->deletedAt, 'Song 3 should be tombstoned');

		$activeSongs = $this->songMapper->findByLibrary($lib->id, includeDeleted: false);
		self::assertCount(1, $activeSongs);
		self::assertSame('Multi Song 2', $activeSongs[0]->title);
	}

	public function testAlreadyDeletedItemIgnoredWhenMobileSheetsUploadsWithoutIt(): void {
		$lib = $this->createTestLibrary();

		$uuid = 'already-deleted-' . bin2hex(random_bytes(3));
		$song = new InternalSong(
			uuid: $uuid,
			libraryId: $lib->id,
			title: 'Already Deleted Song',
			sortTitle: 'Already Deleted Song',
			rating: 1,
			difficulty: 1,
			duration: 100,
			clientId: 110,
			createdAt: 1000,
			updatedAt: 1000,
			deletedAt: 1500,
		);
		$this->songMapper->insert($song);

		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);

		// MobileSheets has sync metadata with older timestamp
		$msDb->exec(sprintf(
			"INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES ('song', '%s', 110, 1000, '{}')",
			$uuid,
		));

		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(0, $stats['songsDeleted'], 'Already deleted song should not increment songsDeleted count');

		$reloaded = $this->songMapper->findById($song->id);
		self::assertNotNull($reloaded);
		self::assertSame(1500, $reloaded->deletedAt, 'deletedAt timestamp should remain unchanged');
	}

	public function testNextcloudDeletedItemsExcludedFromExport(): void {
		$lib = $this->createTestLibrary('NotenFolder');
		$adminUser = $this->userManager->get('admin');
		$userFolder = $this->rootFolder->getUserFolder('admin');

		$testSubfolderName = 'TestDelMusic_' . bin2hex(random_bytes(3));
		$testFolder = $userFolder->newFolder($testSubfolderName);
		$this->createdUserNodes[] = $testFolder;

		$fileA = $testFolder->newFile('fileA.pdf');
		$fileA->putContent("%PDF-1.4 File A\n");
		$this->createdUserNodes[] = $fileA;

		$fileB = $testFolder->newFile('fileB.pdf');
		$fileB->putContent("%PDF-1.4 File B\n");
		$this->createdUserNodes[] = $fileB;

		// 1. Two Songs: Song 1 (to be deleted), Song 2 (to keep)
		$song1 = new InternalSong(
			uuid: 'del-song-1',
			libraryId: $lib->id,
			title: 'Deleted Song 1',
			sortTitle: 'Deleted Song 1',
			rating: 1,
			difficulty: 1,
			duration: 100,
			clientId: 10,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song1);

		$song2 = new InternalSong(
			uuid: 'active-song-2',
			libraryId: $lib->id,
			title: 'Active Song 2',
			sortTitle: 'Active Song 2',
			rating: 5,
			difficulty: 3,
			duration: 200,
			clientId: 20,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song2);

		// 2. Two Composers: Composer 1 (deleted), Composer 2 (active)
		$comp1 = new InternalEntity(
			uuid: 'comp-del-1',
			libraryId: $lib->id,
			type: 'composer',
			name: 'Deleted Composer',
			sortBy: 1,
			ascending: 1,
			clientId: 100,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($comp1);

		$comp2 = new InternalEntity(
			uuid: 'comp-act-2',
			libraryId: $lib->id,
			type: 'composer',
			name: 'Active Composer',
			sortBy: 1,
			ascending: 1,
			clientId: 200,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($comp2);

		// 3. Two Files: File 1 (deleted), File 2 (active)
		$songFile1 = new InternalSongFile(
			uuid: 'file-del-1',
			songId: $song2->id,
			fileId: (int)$fileA->getId(),
			pageOrder: '1',
			pageCount: 1,
			fileSize: 1024,
			fileHash: 111,
			type: 1,
			clientId: 1000,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songFileMapper->insert($songFile1);

		$songFile2 = new InternalSongFile(
			uuid: 'file-act-2',
			songId: $song2->id,
			fileId: (int)$fileB->getId(),
			pageOrder: '1-2',
			pageCount: 2,
			fileSize: 2048,
			fileHash: 222,
			type: 1,
			clientId: 2000,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songFileMapper->insert($songFile2);

		// Soft-delete in Nextcloud
		$this->songMapper->delete($song1->id, hardDelete: false);
		$this->entityMapper->delete($comp1->id, hardDelete: false);
		$this->songFileMapper->delete($songFile1->id, hardDelete: false);

		// Export
		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath);

		$db = new PDO('sqlite:' . $exportPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

		$songs = $db->query('SELECT Id, Title FROM Songs')->fetchAll();
		self::assertCount(1, $songs);
		self::assertSame(20, (int)$songs[0]['Id']);
		self::assertSame('Active Song 2', $songs[0]['Title']);

		$composers = $db->query('SELECT Id, Name FROM Composer')->fetchAll();
		self::assertCount(1, $composers);
		self::assertSame(200, (int)$composers[0]['Id']);
		self::assertSame('Active Composer', $composers[0]['Name']);

		$files = $db->query('SELECT Id, FileHash FROM Files')->fetchAll();
		self::assertCount(1, $files);
		self::assertSame(2000, (int)$files[0]['Id']);
		self::assertSame(222, (int)$files[0]['FileHash']);

		// Verify metadata only includes active items
		$meta = $db->query('SELECT entity_type, uuid FROM _nc_sync_metadata')->fetchAll();
		$metaUuids = array_column($meta, 'uuid');
		self::assertContains('active-song-2', $metaUuids);
		self::assertContains('comp-act-2', $metaUuids);
		self::assertContains('file-act-2', $metaUuids);
		self::assertNotContains('del-song-1', $metaUuids);
		self::assertNotContains('comp-del-1', $metaUuids);
		self::assertNotContains('file-del-1', $metaUuids);
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
			'Years' => ['YearsSongs', 'YearId', 'Years', 'year', 'Year'],
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
		$matchingLinks1 = array_filter($links1, fn ($l) => $l['song_id'] === $song->id && $l['type'] === $entityType);
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
		$matchingLinks2 = array_filter($links2, fn ($l) => $l['song_id'] === $song->id && $l['type'] === $entityType);
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
		$matchingLinks3 = array_filter($links3, fn ($l) => $l['song_id'] === $song->id && $l['type'] === $entityType);
		self::assertCount(0, $matchingLinks3, 'All links for this song and type should be removed');
	}

	/**
	 * @dataProvider linkTableProvider
	 */
	public function testEntityDeletionWhenLinkedToSong(string $linkTable, string $linkCol, string $entityTable, string $entityType, string $nameCol): void {
		$lib = $this->createTestLibrary();

		$song = new InternalSong(
			uuid: 'song-link-del-' . bin2hex(random_bytes(3)),
			libraryId: $lib->id,
			title: 'Song With Linked Entity',
			sortTitle: 'Song With Linked Entity',
			rating: 3,
			difficulty: 2,
			duration: 150,
			clientId: 1,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song);

		$entity = new InternalEntity(
			uuid: 'ent-link-del-' . bin2hex(random_bytes(3)),
			libraryId: $lib->id,
			type: $entityType,
			name: ($nameCol === 'Year' ? '2026' : 'Entity For Deletion'),
			sortBy: 1,
			ascending: 1,
			clientId: 10,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($entity);
		$this->entityMapper->setSongEntitiesForType($song->id, $entityType, [$entity->id]);

		// 1. Export to SQLite
		$exportPath1 = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath1);

		// Verify exported SQLite DB has entity and link
		$msDb = new PDO('sqlite:' . $exportPath1, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$actualEntityTable = $entityTable;
		$check = $msDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $actualEntityTable) . "'");
		if ($check === false || $check->fetch() === false) {
			if ($actualEntityTable === 'Key') {
				$actualEntityTable = 'Keys';
			} elseif ($actualEntityTable === 'SourceType') {
				$actualEntityTable = 'SourceTypes';
			}
		}

		$countEnt = (int)$msDb->query(sprintf('SELECT COUNT(*) FROM "%s" WHERE Id = 10', $actualEntityTable))->fetchColumn();
		$countLink = (int)$msDb->query(sprintf('SELECT COUNT(*) FROM "%s" WHERE "%s" = 10 AND SongId = 1', $linkTable, $linkCol))->fetchColumn();
		self::assertSame(1, $countEnt);
		self::assertSame(1, $countLink);

		// 2. MobileSheets deletes the entity and the link
		$msDb->exec(sprintf('DELETE FROM "%s" WHERE Id = 10', $actualEntityTable));
		$msDb->exec(sprintf('DELETE FROM "%s" WHERE "%s" = 10', $linkTable, $linkCol));

		// 3. MobileSheets uploads DB back (triggering import)
		$stats = $this->importer->importFromFile($lib, $exportPath1);
		self::assertSame(1, $stats['entitiesDeleted']);

		$reloadedEntity = $this->entityMapper->findById($entity->id);
		self::assertNotNull($reloadedEntity);
		self::assertNotNull($reloadedEntity->deletedAt, 'Entity should be tombstoned');

		$reloadedSong = $this->songMapper->findById($song->id);
		self::assertNotNull($reloadedSong);
		self::assertNull($reloadedSong->deletedAt, 'Song should remain active');

		$activeLinks = $this->entityMapper->findAllSongEntityLinksForLibrary($lib->id);
		$matchingLinks = array_filter($activeLinks, fn ($l) => $l['song_id'] === $song->id && $l['type'] === $entityType);
		self::assertCount(0, $matchingLinks, 'Active links for this entity type should be 0');

		// 4. Re-export and verify neither entity nor link appears in final DB
		$exportPath2 = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath2);

		$finalDb = new PDO('sqlite:' . $exportPath2, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$finalEntCount = (int)$finalDb->query(sprintf('SELECT COUNT(*) FROM "%s"', $actualEntityTable))->fetchColumn();
		$finalLinkCount = (int)$finalDb->query(sprintf('SELECT COUNT(*) FROM "%s"', $linkTable))->fetchColumn();
		$finalSongCount = (int)$finalDb->query('SELECT COUNT(*) FROM Songs')->fetchColumn();

		self::assertSame(0, $finalEntCount);
		self::assertSame(0, $finalLinkCount);
		self::assertSame(1, $finalSongCount);
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

	public function testSongDeletionWithLinkedEntitiesAndFilesExportCycle(): void {
		$lib = $this->createTestLibrary('NotenFolder');
		$adminUser = $this->userManager->get('admin');
		$userFolder = $this->rootFolder->getUserFolder('admin');

		$testSubfolderName = 'TestDelCycle_' . bin2hex(random_bytes(3));
		$testFolder = $userFolder->newFolder($testSubfolderName);
		$this->createdUserNodes[] = $testFolder;

		$testFile = $testFolder->newFile('cycle.pdf');
		$testFile->putContent("%PDF-1.4 Cycle content\n");
		$this->createdUserNodes[] = $testFile;

		// Song with clientId 1
		$song = new InternalSong(
			uuid: 'cycle-song-del',
			libraryId: $lib->id,
			title: 'Song To Delete In Cycle',
			sortTitle: 'Song To Delete In Cycle',
			rating: 4,
			difficulty: 3,
			duration: 240,
			clientId: 1,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song);

		// File with clientId 10
		$songFile = new InternalSongFile(
			uuid: 'cycle-file-del',
			songId: $song->id,
			fileId: (int)$testFile->getId(),
			pageOrder: '1',
			pageCount: 1,
			fileSize: 1024,
			fileHash: 333,
			type: 1,
			clientId: 10,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songFileMapper->insert($songFile);

		// Composer with clientId 20
		$composer = new InternalEntity(
			uuid: 'cycle-comp-del',
			libraryId: $lib->id,
			type: 'composer',
			name: 'Cycle Composer',
			sortBy: 1,
			ascending: 1,
			clientId: 20,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($composer);
		$this->entityMapper->setSongEntitiesForType($song->id, 'composer', [$composer->id]);

		// 1. Export to SQLite
		$exportPath1 = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath1);

		// 2. MobileSheets deletes the song and file, but keeps the composer
		$msDb = new PDO('sqlite:' . $exportPath1, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$msDb->exec('DELETE FROM Songs WHERE Id = 1');
		$msDb->exec('DELETE FROM Files WHERE Id = 10');
		$msDb->exec('DELETE FROM ComposerSongs WHERE SongId = 1');

		// 3. MobileSheets uploads back
		$stats = $this->importer->importFromFile($lib, $exportPath1, $adminUser);
		self::assertSame(1, $stats['songsDeleted']);
		self::assertSame(1, $stats['filesDeleted']);
		self::assertSame(0, $stats['entitiesDeleted']);

		$reloadedSong = $this->songMapper->findById($song->id);
		self::assertNotNull($reloadedSong);
		self::assertNotNull($reloadedSong->deletedAt, 'Song should be tombstoned');

		$reloadedFile = $this->songFileMapper->findById($songFile->id);
		self::assertNotNull($reloadedFile);
		self::assertNotNull($reloadedFile->deletedAt, 'File should be tombstoned');

		$reloadedComp = $this->entityMapper->findById($composer->id);
		self::assertNotNull($reloadedComp);
		self::assertNull($reloadedComp->deletedAt, 'Composer should remain active');

		// 4. Nextcloud exports again
		$exportPath2 = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath2);

		$finalDb = new PDO('sqlite:' . $exportPath2, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		self::assertSame(0, (int)$finalDb->query('SELECT COUNT(*) FROM Songs')->fetchColumn());
		self::assertSame(0, (int)$finalDb->query('SELECT COUNT(*) FROM Files')->fetchColumn());
		self::assertSame(0, (int)$finalDb->query('SELECT COUNT(*) FROM ComposerSongs')->fetchColumn());
		self::assertSame(1, (int)$finalDb->query('SELECT COUNT(*) FROM Composer')->fetchColumn());
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
			$msDb->exec('INSERT INTO MetronomeSettings (Id, SongId, Bpm, Sig1, Sig2) VALUES (1, 100, 132, 3, 4)');
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
		$msDb->exec('DELETE FROM Composer WHERE Id = 10');
		$msDb->exec('DELETE FROM ComposerSongs WHERE ComposerId = 10');
		$msDb->exec("INSERT INTO Composer (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES (20, 'Composer B', 1, 1, 2000000, 2000000)");
		$msDb->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (20, 2)');

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

	public function testSongDeletionConflictNextcloudWins(): void {
		$lib = $this->createTestLibrary('LwwSongDeletion');

		$song = new InternalSong(
			uuid: 'lww-song-uuid-' . bin2hex(random_bytes(3)),
			libraryId: $lib->id,
			title: 'Song Original',
			sortTitle: 'Song Original',
			rating: 2,
			difficulty: 1,
			duration: 100,
			clientId: 1,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song);

		// 1. Export database (baseline metadata has updated_at = 1000)
		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath);

		// 2. MobileSheets deletes the song while offline
		$msDb = new PDO('sqlite:' . $exportPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$msDb->exec('DELETE FROM Songs WHERE Id = 1');

		// 3. Concurrently, Nextcloud modifies the song at timestamp 2000 > 1000
		$song->title = 'Song Modified in Nextcloud';
		$song->updatedAt = 2000;
		$this->songMapper->update($song);

		// 4. MobileSheets uploads the database back
		$stats = $this->importer->importFromFile($lib, $exportPath);

		// Deletion should be rejected because Nextcloud's updatedAt > metadata updated_at
		self::assertSame(0, $stats['songsDeleted'], 'Song deletion should be rejected because Nextcloud was updated after export');

		$reloaded = $this->songMapper->findById($song->id);
		self::assertNotNull($reloaded);
		self::assertNull($reloaded->deletedAt, 'Song should remain active');
		self::assertSame('Song Modified in Nextcloud', $reloaded->title);
		self::assertSame(2000, $reloaded->updatedAt);
	}

	public function testEntityDeletionConflictNextcloudWins(): void {
		$lib = $this->createTestLibrary('LwwEntityDeletion');

		$entity = new InternalEntity(
			uuid: 'lww-ent-uuid-' . bin2hex(random_bytes(3)),
			libraryId: $lib->id,
			type: 'composer',
			name: 'Original Composer',
			sortBy: 1,
			ascending: 1,
			clientId: 10,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->entityMapper->insert($entity);

		// 1. Export database
		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath);

		// 2. MobileSheets deletes the entity
		$msDb = new PDO('sqlite:' . $exportPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$msDb->exec('DELETE FROM Composer WHERE Id = 10');

		// 3. Concurrently, Nextcloud updates the entity at timestamp 2000 > 1000
		$entity->name = 'Updated Composer in Nextcloud';
		$entity->updatedAt = 2000;
		$this->entityMapper->update($entity);

		// 4. MobileSheets uploads DB back
		$stats = $this->importer->importFromFile($lib, $exportPath);

		self::assertSame(0, $stats['entitiesDeleted'], 'Entity deletion should be rejected because Nextcloud was updated after export');

		$reloaded = $this->entityMapper->findById($entity->id);
		self::assertNotNull($reloaded);
		self::assertNull($reloaded->deletedAt, 'Entity should remain active');
		self::assertSame('Updated Composer in Nextcloud', $reloaded->name);
		self::assertSame(2000, $reloaded->updatedAt);
	}

	public function testFileDeletionConflictNextcloudWins(): void {
		$lib = $this->createTestLibrary('LwwFileDeletion');
		$adminUser = $this->userManager->get('admin');
		self::assertNotNull($adminUser);
		$userFolder = $this->rootFolder->getUserFolder('admin');

		$testFile = $userFolder->newFile('lww_test_' . bin2hex(random_bytes(3)) . '.pdf');
		$testFile->putContent("%PDF-1.4 LWW Content\n");
		$this->createdUserNodes[] = $testFile;

		$song = new InternalSong(
			uuid: 'lww-file-song-' . bin2hex(random_bytes(3)),
			libraryId: $lib->id,
			title: 'Song with File',
			sortTitle: 'Song with File',
			rating: 1,
			difficulty: 1,
			duration: 60,
			clientId: 1,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songMapper->insert($song);

		$songFile = new InternalSongFile(
			uuid: 'lww-file-uuid-' . bin2hex(random_bytes(3)),
			songId: $song->id,
			fileId: (int)$testFile->getId(),
			pageOrder: '1',
			pageCount: 1,
			fileSize: 100,
			fileHash: 999,
			type: 1,
			clientId: 100,
			createdAt: 1000,
			updatedAt: 1000,
		);
		$this->songFileMapper->insert($songFile);

		// 1. Export database
		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath);

		// 2. MobileSheets deletes file row
		$msDb = new PDO('sqlite:' . $exportPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$msDb->exec('DELETE FROM Files WHERE Id = 100');

		// 3. Concurrently, Nextcloud updates the file entry at timestamp 2000 > 1000
		$songFile->pageOrder = '1-3';
		$songFile->updatedAt = 2000;
		$this->songFileMapper->update($songFile);

		// 4. MobileSheets uploads back
		$stats = $this->importer->importFromFile($lib, $exportPath, $adminUser);

		self::assertSame(0, $stats['filesDeleted'], 'File deletion should be rejected because Nextcloud was updated after export');

		$reloaded = $this->songFileMapper->findById($songFile->id);
		self::assertNotNull($reloaded);
		self::assertNull($reloaded->deletedAt, 'File should remain active');
		self::assertSame('1-3', $reloaded->pageOrder);
		self::assertSame(2000, $reloaded->updatedAt);
	}

	public function testSongPresentInNextcloudNeverInUploadedDbIsPreserved(): void {
		$lib = $this->createTestLibrary('PreserveNcSong');

		// 1. Song exists in Nextcloud
		$ncSong = new InternalSong(
			uuid: 'nc-only-song-' . bin2hex(random_bytes(3)),
			libraryId: $lib->id,
			title: 'Nextcloud Exclusive Song',
			sortTitle: 'Nextcloud Exclusive Song',
			rating: 5,
			difficulty: 4,
			duration: 300,
			clientId: 1,
			createdAt: 1500,
			updatedAt: 1500,
		);
		$this->songMapper->insert($ncSong);

		// 2. An uploaded MobileSheets DB from another device or backup only has its own song
		$dbPath = $this->tempDbPath();
		$msDb = $this->createEmptyMobileSheetsDb($dbPath);
		$msDb->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, DateCreated, LastModified) VALUES (2, 'MS New Song', 'MS New Song', 3, 2, 180, 1000000, 1600000000000)");

		// Note: The uploaded DB has NO _nc_sync_metadata referencing ncSong, or it's a completely different DB
		$stats = $this->importer->importFromFile($lib, $dbPath);
		self::assertSame(1, $stats['songsCreated'], 'MS New Song should be created');
		self::assertSame(0, $stats['songsDeleted'], 'NC Exclusive Song must NOT be deleted');

		// 3. Verify both songs exist in Nextcloud
		$allSongs = $this->songMapper->findByLibrary($lib->id, includeDeleted: false);
		self::assertCount(2, $allSongs);

		$reloadedNcSong = $this->songMapper->findById($ncSong->id);
		self::assertNotNull($reloadedNcSong);
		self::assertNull($reloadedNcSong->deletedAt);
		self::assertSame('Nextcloud Exclusive Song', $reloadedNcSong->title);

		// 4. Exporting to a fresh DB includes both songs
		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath);

		$finalDb = new PDO('sqlite:' . $exportPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$titles = $finalDb->query('SELECT Title FROM Songs ORDER BY Title ASC')->fetchAll(PDO::FETCH_COLUMN);
		self::assertSame(['MS New Song', 'Nextcloud Exclusive Song'], $titles);
	}
}
