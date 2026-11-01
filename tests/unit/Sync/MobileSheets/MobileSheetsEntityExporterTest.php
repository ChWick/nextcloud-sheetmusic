<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityExporter;
use PDO;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsEntityExporterTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private MobileSheetsEntityExporter $entityExporter;
	private PDO $sqliteDb;
	private string $tempDbPath;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('EntityExporter Test Lib');
		$this->libraryId = (int)$library->id;

		$this->entityExporter = new MobileSheetsEntityExporter(
			$this->entityMapper,
			$this->songMapper,
		);

		$this->tempDbPath = tempnam(sys_get_temp_dir(), 'entity_exporter_') . '.db';
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

	public function testExportEntitiesExportsAllEntitiesAndAssignsClientIds(): void {
		$this->sqliteDb->exec('CREATE TABLE Composer (Id INTEGER PRIMARY KEY, Name TEXT, LastModified INTEGER, DateCreated INTEGER, SortBy INTEGER, Ascending INTEGER)');
		$this->sqliteDb->exec('CREATE TABLE _nc_sync_metadata (entity_type TEXT, uuid TEXT, client_id INTEGER, updated_at INTEGER, extra_json TEXT)');

		$e1 = new InternalEntity(
			uuid: 'comp-uuid-1',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Frederic Chopin',
			clientId: 1,
			createdAt: 1000,
			updatedAt: 2000,
		);
		$this->entityMapper->insert($e1);

		$e2 = new InternalEntity(
			uuid: 'comp-uuid-2',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Franz Liszt',
			clientId: null,
			createdAt: 1500,
			updatedAt: 2500,
		);
		$this->entityMapper->insert($e2);

		$this->entityExporter->exportEntities($this->sqliteDb, $this->libraryId);

		$rows = $this->sqliteDb->query('SELECT * FROM Composer ORDER BY Id ASC')->fetchAll();
		$this->assertCount(2, $rows);
		$this->assertSame('Frederic Chopin', $rows[0]['Name']);
		$this->assertSame(1, (int)$rows[0]['Id']);
		$this->assertSame('Franz Liszt', $rows[1]['Name']);
		$this->assertGreaterThan(1, (int)$rows[1]['Id']);

		// Verify metadata was written
		$meta = $this->sqliteDb->query('SELECT * FROM _nc_sync_metadata')->fetchAll();
		$this->assertCount(2, $meta);
	}

	public function testExportEntitiesMaintainsIndependentClientIdPerTable(): void {
		$this->sqliteDb->exec('CREATE TABLE Composer (Id INTEGER PRIMARY KEY, Name TEXT, LastModified INTEGER, DateCreated INTEGER, SortBy INTEGER, Ascending INTEGER)');
		$this->sqliteDb->exec('CREATE TABLE Collections (Id INTEGER PRIMARY KEY, Name TEXT, LastModified INTEGER, DateCreated INTEGER, SortBy INTEGER, Ascending INTEGER)');
		$this->sqliteDb->exec('CREATE TABLE Books (Id INTEGER PRIMARY KEY, Title TEXT, LastModified INTEGER, DateCreated INTEGER, SortBy INTEGER, Ascending INTEGER)');

		$coll = new InternalEntity(
			uuid: 'coll-uuid-1',
			libraryId: $this->libraryId,
			type: 'collection',
			name: 'My Collection',
			clientId: null,
		);
		$this->entityMapper->insert($coll);

		$comp = new InternalEntity(
			uuid: 'comp-uuid-x',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Beethoven',
			clientId: null,
		);
		$this->entityMapper->insert($comp);

		$book = new InternalEntity(
			uuid: 'book-uuid-1',
			libraryId: $this->libraryId,
			type: 'book',
			name: 'Songbook',
			clientId: null,
		);
		$this->entityMapper->insert($book);

		$this->entityExporter->exportEntities($this->sqliteDb, $this->libraryId);

		$collRows = $this->sqliteDb->query('SELECT Id, Name FROM Collections')->fetchAll();
		$compRows = $this->sqliteDb->query('SELECT Id, Name FROM Composer')->fetchAll();
		$bookRows = $this->sqliteDb->query('SELECT Id, Title FROM Books')->fetchAll();

		$this->assertCount(1, $collRows);
		$this->assertSame(1, (int)$collRows[0]['Id']);

		$this->assertCount(1, $compRows);
		$this->assertSame(1, (int)$compRows[0]['Id'], 'Composer table must start at Id 1, not be offset by Collections');

		$this->assertCount(1, $bookRows);
		$this->assertSame(1, (int)$bookRows[0]['Id'], 'Books table must start at Id 1, not be offset by other tables');
	}

	public function testExportSongEntityLinksRespectsValidSongIdsAndOrdering(): void {
		$this->sqliteDb->exec('CREATE TABLE Composer (Id INTEGER PRIMARY KEY, Name TEXT, SortBy INTEGER, Ascending INTEGER)');
		$this->sqliteDb->exec('CREATE TABLE ComposerSongs (Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');

		$composer = new InternalEntity(
			uuid: 'comp-1',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Chopin',
			clientId: 1,
			sortBy: 1, // Title sort
			ascending: 1,
		);
		$composer = $this->entityMapper->insert($composer);

		$songValid = new InternalSong(
			uuid: 'song-v',
			libraryId: $this->libraryId,
			title: 'Nocturne in E-flat',
			clientId: 10,
		);
		$songValid = $this->songMapper->insert($songValid);

		$songExcluded = new InternalSong(
			uuid: 'song-x',
			libraryId: $this->libraryId,
			title: 'Ballade No. 1',
			clientId: 20,
		);
		$songExcluded = $this->songMapper->insert($songExcluded);

		$this->entityMapper->linkSongEntity((int)$songValid->id, (int)$composer->id);
		$this->entityMapper->linkSongEntity((int)$songExcluded->id, (int)$composer->id);

		// Only songValid has a valid file
		$validSongIds = [(int)$songValid->id => true];

		$this->entityExporter->exportSongEntityLinks($this->sqliteDb, $this->libraryId, $validSongIds);

		$links = $this->sqliteDb->query('SELECT * FROM ComposerSongs')->fetchAll();
		$this->assertCount(1, $links);
		$this->assertSame(1, (int)$links[0]['ComposerId']);
		$this->assertSame(10, (int)$links[0]['SongId']);
	}
}
