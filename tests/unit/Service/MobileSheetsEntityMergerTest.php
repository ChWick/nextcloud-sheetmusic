<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Service\MobileSheetsEntityMerger;
use OCA\SheetMusic\Service\MobileSheetsSyncMetadata;
use PDO;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsEntityMergerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private MobileSheetsEntityMerger $merger;
	private PDO $sqliteDb;
	private string $tempDbPath;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('EntityMerger Test Lib');
		$this->libraryId = (int)$library->id;

		$this->merger = new MobileSheetsEntityMerger(
			$this->entityMapper,
			$this->songMapper,
			$this->miscMapper,
		);

		$this->tempDbPath = tempnam(sys_get_temp_dir(), 'entity_merger_') . '.db';
		$this->sqliteDb = new PDO('sqlite:' . $this->tempDbPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}

	protected function tearDown(): void {
		if (file_exists($this->tempDbPath)) {
			@unlink($this->tempDbPath);
		}
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testMergeEntitiesCreatesNewEntitiesAndUpdatesExisting(): void {
		$this->sqliteDb->exec('CREATE TABLE Composer (Id INTEGER PRIMARY KEY, Name TEXT, LastModified INTEGER, DateCreated INTEGER, SortBy INTEGER, Ascending INTEGER)');
		$this->sqliteDb->exec("INSERT INTO Composer (Id, Name, LastModified, DateCreated) VALUES (1, 'Ludwig van Beethoven', 1700000000000, 1600000000000)");
		$this->sqliteDb->exec("INSERT INTO Composer (Id, Name, LastModified, DateCreated) VALUES (2, 'Wolfgang Amadeus Mozart', 1700000000000, 1600000000000)");

		$syncMeta = MobileSheetsSyncMetadata::fromDatabase($this->sqliteDb);
		$stats = ['entitiesCreated' => 0, 'entitiesUpdated' => 0, 'entitiesDeleted' => 0];

		$this->merger->mergeEntities($this->sqliteDb, $this->libraryId, $syncMeta, $stats);

		$this->assertSame(2, $stats['entitiesCreated']);
		$this->assertSame(0, $stats['entitiesUpdated']);

		$entities = $this->entityMapper->findAllForLibrary($this->libraryId);
		$this->assertCount(2, $entities);

		// Now update one in SQLite with newer timestamp
		$this->sqliteDb->exec("UPDATE Composer SET Name = 'L. v. Beethoven', LastModified = 1750000000000 WHERE Id = 1");
		$this->merger->mergeEntities($this->sqliteDb, $this->libraryId, $syncMeta, $stats);

		$this->assertSame(2, $stats['entitiesCreated']);
		$this->assertSame(1, $stats['entitiesUpdated']);

		$beethoven = $this->entityMapper->findByClientId($this->libraryId, 'composer', 1);
		$this->assertNotNull($beethoven);
		$this->assertSame('L. v. Beethoven', $beethoven->name);
	}

	public function testMergeSongEntityLinksLinksSongsToEntities(): void {
		$this->sqliteDb->exec('CREATE TABLE Composer (Id INTEGER PRIMARY KEY, Name TEXT)');
		$this->sqliteDb->exec('CREATE TABLE ComposerSongs (Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$this->sqliteDb->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Johann Sebastian Bach')");
		$this->sqliteDb->exec('INSERT INTO ComposerSongs (Id, ComposerId, SongId) VALUES (1, 1, 10)');

		// Insert song into internal database with clientId 10
		$song = new InternalSong(
			uuid: 'song-uuid-1',
			libraryId: $this->libraryId,
			title: 'Air on the G String',
			clientId: 10,
		);
		$song = $this->songMapper->insert($song);

		$syncMeta = MobileSheetsSyncMetadata::fromDatabase($this->sqliteDb);
		$stats = ['entitiesCreated' => 0, 'entitiesUpdated' => 0, 'entitiesDeleted' => 0];
		$this->merger->mergeEntities($this->sqliteDb, $this->libraryId, $syncMeta, $stats);

		$this->merger->mergeSongEntityLinks($this->sqliteDb, $this->libraryId, [10 => true]);

		$bach = $this->entityMapper->findByClientId($this->libraryId, 'composer', 1);
		$this->assertNotNull($bach);
		$songIds = $this->entityMapper->findSongIdsForEntity((int)$bach->id);
		$this->assertEquals([(int)$song->id], $songIds);
	}

	public function testProcessEntityDeletionsTombstonesDeletedEntities(): void {
		$this->sqliteDb->exec('CREATE TABLE Composer (Id INTEGER PRIMARY KEY, Name TEXT)');
		$this->sqliteDb->exec('CREATE TABLE _nc_sync_metadata (entity_type TEXT, uuid TEXT, client_id INTEGER, updated_at INTEGER, extra_json TEXT)');

		// Insert entity in internal DB
		$entity = new InternalEntity(
			uuid: 'entity-uuid-to-delete',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Deleted Composer',
			clientId: 5,
			updatedAt: 1600000000,
		);
		$entity = $this->entityMapper->insert($entity);

		$stmt = $this->sqliteDb->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:t, :u, :c, :up, :e)');
		$stmt->execute([
			':t' => 'composer',
			':u' => 'entity-uuid-to-delete',
			':c' => 5,
			':up' => 1600000000,
			':e' => '{}',
		]);

		$syncMeta = MobileSheetsSyncMetadata::fromDatabase($this->sqliteDb);
		$stats = ['entitiesCreated' => 0, 'entitiesUpdated' => 0, 'entitiesDeleted' => 0];

		// Table Composer is empty, so client deleted composer 5
		$this->merger->processEntityDeletions($this->sqliteDb, $this->libraryId, $syncMeta, $stats);

		$this->assertSame(1, $stats['entitiesDeleted']);
		$deleted = $this->entityMapper->findById((int)$entity->id);
		$this->assertNotNull($deleted);
		$this->assertNotNull($deleted->deletedAt);
	}
}
