<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsTableHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class MobileSheetsTableHelperTest extends TestCase {
	private PDO $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = new PDO('sqlite::memory:', options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}

	public function testTableExists(): void {
		self::assertFalse(MobileSheetsTableHelper::tableExists($this->db, 'Songs'));

		$this->db->exec('CREATE TABLE Songs (Id INTEGER PRIMARY KEY, Title TEXT)');
		self::assertTrue(MobileSheetsTableHelper::tableExists($this->db, 'Songs'));
	}

	public function testResolveTableWithVariants(): void {
		self::assertNull(MobileSheetsTableHelper::resolveTable($this->db, 'Key'));

		// Case 1: Key exists
		$this->db->exec('CREATE TABLE "Key" (Id INTEGER PRIMARY KEY, Name TEXT)');
		self::assertSame('Key', MobileSheetsTableHelper::resolveTable($this->db, 'Key'));
		self::assertSame('Key', MobileSheetsTableHelper::resolveTable($this->db, 'Keys'));

		// Case 2: SourceTypes exists
		$this->db->exec('CREATE TABLE SourceTypes (Id INTEGER PRIMARY KEY, Type TEXT)');
		self::assertSame('SourceTypes', MobileSheetsTableHelper::resolveTable($this->db, 'SourceType'));
		self::assertSame('SourceTypes', MobileSheetsTableHelper::resolveTable($this->db, 'SourceTypes'));
	}

	public function testGetTableColumns(): void {
		$this->db->exec('CREATE TABLE Test (Id INTEGER PRIMARY KEY, MyColumn TEXT, AnotherCol INTEGER)');
		$cols = MobileSheetsTableHelper::getTableColumns($this->db, 'Test');

		self::assertArrayHasKey('id', $cols);
		self::assertArrayHasKey('mycolumn', $cols);
		self::assertSame('MyColumn', $cols['mycolumn']);
		self::assertSame('AnotherCol', $cols['anothercol']);
	}

	public function testGetSongNotesContentColumn(): void {
		self::assertNull(MobileSheetsTableHelper::getSongNotesContentColumn($this->db));

		$this->db->exec('CREATE TABLE SongNotes (Id INTEGER PRIMARY KEY, SongId INTEGER, Notes TEXT)');
		self::assertSame('Notes', MobileSheetsTableHelper::getSongNotesContentColumn($this->db));
	}

	public function testResolveEntityNameColumn(): void {
		$cols = ['title' => 'Title', 'id' => 'Id'];
		self::assertSame('Title', MobileSheetsTableHelper::resolveEntityNameColumn($cols, 'Name'));
		self::assertNull(MobileSheetsTableHelper::resolveEntityNameColumn(['id' => 'Id'], 'Custom'));
	}

	public function testInsertRow(): void {
		$this->db->exec('CREATE TABLE TestInsert (Id INTEGER PRIMARY KEY, Name TEXT, Value INTEGER)');

		// Standard insert
		MobileSheetsTableHelper::insertRow($this->db, 'TestInsert', ['Id' => 1, 'Name' => 'Alpha', 'Value' => 10]);
		$row = $this->db->query('SELECT * FROM TestInsert WHERE Id = 1')->fetch();
		self::assertSame('Alpha', $row['Name']);
		self::assertSame(10, (int)$row['Value']);

		// Insert or replace
		MobileSheetsTableHelper::insertRow($this->db, 'TestInsert', ['Id' => 1, 'Name' => 'Alpha Updated', 'Value' => 20], orReplace: true);
		$row = $this->db->query('SELECT * FROM TestInsert WHERE Id = 1')->fetch();
		self::assertSame('Alpha Updated', $row['Name']);
		self::assertSame(20, (int)$row['Value']);

		// Empty row does nothing
		MobileSheetsTableHelper::insertRow($this->db, 'TestInsert', []);
		$cnt = (int)$this->db->query('SELECT count(*) FROM TestInsert')->fetchColumn();
		self::assertSame(1, $cnt);
	}

	public function testGetTableIdSet(): void {
		self::assertSame([], MobileSheetsTableHelper::getTableIdSet($this->db, 'NonExistent'));

		$this->db->exec('CREATE TABLE TestIds (Id INTEGER PRIMARY KEY, Val TEXT)');
		$this->db->exec('INSERT INTO TestIds (Id, Val) VALUES (10, "A"), (25, "B"), (99, "C")');

		$idSet = MobileSheetsTableHelper::getTableIdSet($this->db, 'TestIds');
		self::assertCount(3, $idSet);
		self::assertTrue(isset($idSet[10]));
		self::assertTrue(isset($idSet[25]));
		self::assertTrue(isset($idSet[99]));
		self::assertFalse(isset($idSet[11]));
	}

	public function testFilterToExistingColumns(): void {
		$cols = ['title' => 'Title', 'stars' => 'Stars'];
		$candidates = ['Title' => 'My Song', 'STARS' => 4, 'nonexistent' => 'ignored'];

		$filtered = MobileSheetsTableHelper::filterToExistingColumns($cols, $candidates);
		self::assertSame(['Title' => 'My Song', 'Stars' => 4], $filtered);
	}

	public function testUpdateRow(): void {
		$this->db->exec('CREATE TABLE TestUpdate (Id INTEGER PRIMARY KEY, Title TEXT, Stars INTEGER)');
		$this->db->exec('INSERT INTO TestUpdate (Id, Title, Stars) VALUES (1, "Original", 2)');

		MobileSheetsTableHelper::updateRow($this->db, 'TestUpdate', ['Title' => 'Modified', 'Stars' => 5], 'Id', 1);

		$row = $this->db->query('SELECT * FROM TestUpdate WHERE Id = 1')->fetch();
		self::assertSame('Modified', $row['Title']);
		self::assertSame(5, (int)$row['Stars']);

		// Empty update does nothing
		MobileSheetsTableHelper::updateRow($this->db, 'TestUpdate', [], 'Id', 1);
		self::assertSame('Modified', $row['Title']);
	}

	public function testClientIdTracking(): void {
		$records = [
			(object)['clientId' => 1],
			(object)['clientId' => 3],
			(object)['clientId' => null],
		];

		[$usedIds, $nextId] = MobileSheetsTableHelper::initClientIdTracking($records);
		self::assertTrue($usedIds[1]);
		self::assertTrue($usedIds[3]);
		self::assertFalse(isset($usedIds[2]));
		self::assertSame(4, $nextId);

		// Allocating for existing valid ID returns it
		self::assertSame(1, MobileSheetsTableHelper::nextClientId($usedIds, $nextId, 1));
		self::assertSame(3, MobileSheetsTableHelper::nextClientId($usedIds, $nextId, 3));

		// Allocating for null allocates nextId
		$alloc1 = MobileSheetsTableHelper::nextClientId($usedIds, $nextId, null);
		self::assertSame(4, $alloc1);
		self::assertTrue($usedIds[4]);

		$alloc2 = MobileSheetsTableHelper::nextClientId($usedIds, $nextId, null);
		self::assertSame(5, $alloc2);
		self::assertTrue($usedIds[5]);
	}

	public function testShouldClientWinLwwAndEnsureClientId(): void {
		$record = (object)[
			'clientId' => null,
			'updatedAt' => 100,
			'deletedAt' => null,
		];

		// Client has older timestamp
		self::assertFalse(MobileSheetsTableHelper::shouldClientWinLww($record, 50));
		// When client loses, ensureClientId records the clientId
		self::assertTrue(MobileSheetsTableHelper::ensureClientId($record, 42));
		self::assertSame(42, $record->clientId);
		self::assertFalse(MobileSheetsTableHelper::ensureClientId($record, 42)); // No change if already set

		// Client has newer timestamp
		self::assertTrue(MobileSheetsTableHelper::shouldClientWinLww($record, 150));

		// Tombstone: client older than deletion does not revive
		$tombstoned = (object)[
			'clientId' => 10,
			'updatedAt' => 100,
			'deletedAt' => 200,
		];
		self::assertFalse(MobileSheetsTableHelper::shouldClientWinLww($tombstoned, 150));
		self::assertSame(200, $tombstoned->deletedAt);

		// Tombstone: client newer than deletion and newer than baseline export revives
		self::assertTrue(MobileSheetsTableHelper::shouldClientWinLww($tombstoned, 300, ['updated_at' => 250]));
		self::assertNull($tombstoned->deletedAt);

		// Untracked client LWW
		$untracked = (object)[
			'clientId' => null,
			'updatedAt' => 500,
			'deletedAt' => null,
		];
		self::assertFalse(MobileSheetsTableHelper::shouldClientWinLww($untracked, 400, null, allowUntrackedClientLww: false));
		self::assertTrue(MobileSheetsTableHelper::shouldClientWinLww($untracked, 400, null, allowUntrackedClientLww: true));
	}

	public function testFindRowById(): void {
		$this->db->exec('CREATE TABLE TestFind (Id INTEGER PRIMARY KEY, Title TEXT)');
		$this->db->exec('INSERT INTO TestFind (Id, Title) VALUES (42, "Found It")');

		$found = MobileSheetsTableHelper::findRowById($this->db, 'TestFind', 42);
		self::assertNotNull($found);
		self::assertSame('Found It', $found['Title']);

		$notFound = MobileSheetsTableHelper::findRowById($this->db, 'TestFind', 999);
		self::assertNull($notFound);
	}

	public function testEnsureSyncMetadataTable(): void {
		MobileSheetsTableHelper::ensureSyncMetadataTable($this->db);
		self::assertTrue(MobileSheetsTableHelper::tableExists($this->db, '_nc_sync_metadata'));
	}

	public function testInsertIfEmpty(): void {
		$this->db->exec('CREATE TABLE TestEmpty (Id INTEGER, Val TEXT)');

		MobileSheetsTableHelper::insertIfEmpty($this->db, 'TestEmpty', ['Id' => 1, 'Val' => 'Default']);
		$cnt = (int)$this->db->query('SELECT count(*) FROM TestEmpty')->fetchColumn();
		self::assertSame(1, $cnt);

		// Second call should not insert another row
		MobileSheetsTableHelper::insertIfEmpty($this->db, 'TestEmpty', ['Id' => 2, 'Val' => 'Second']);
		$cnt = (int)$this->db->query('SELECT count(*) FROM TestEmpty')->fetchColumn();
		self::assertSame(1, $cnt);

		// Non-existent table does nothing
		MobileSheetsTableHelper::insertIfEmpty($this->db, 'NonExistentTable', ['Id' => 1]);
	}

	public function testInsertAndQueryBlobPreservesBlobStorageClass(): void {
		$this->db->exec('CREATE TABLE AnnotationPoints (Id INTEGER PRIMARY KEY, AnnotationId INTEGER, Points BLOB, Count INTEGER)');

		$binary = "\x00\x01\x02\x03\x00\xFF\xFE\x00";
		MobileSheetsTableHelper::insertRow($this->db, 'AnnotationPoints', [
			'Id' => 1,
			'AnnotationId' => 42,
			'Points' => $binary,
			'Count' => 8,
		]);

		$row = $this->db->query('SELECT Id, AnnotationId, typeof(Points) AS t, length(Points) AS l, Points, Count FROM AnnotationPoints WHERE Id = 1')->fetch();
		self::assertIsArray($row);
		self::assertSame('blob', $row['t'], 'Storage class for Points must be blob, not text');
		self::assertSame(8, (int)$row['l'], 'Length of Points blob must not be truncated at null bytes');
		self::assertSame($binary, $row['Points']);

		$updatedBinary = "\x00\xAA\xBB\x00\xCC";
		MobileSheetsTableHelper::updateRow($this->db, 'AnnotationPoints', [
			'Points' => $updatedBinary,
			'Count' => 5,
		], 'Id', 1);

		$updRow = $this->db->query('SELECT typeof(Points) AS t, length(Points) AS l, Points, Count FROM AnnotationPoints WHERE Id = 1')->fetch();
		self::assertIsArray($updRow);
		self::assertSame('blob', $updRow['t']);
		self::assertSame(5, (int)$updRow['l']);
		self::assertSame($updatedBinary, $updRow['Points']);
	}
}
