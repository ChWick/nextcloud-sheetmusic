<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBaselineSchema;
use PDO;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

/**
 * Verifies that:
 * 1. MobileSheetsBaselineSchema exactly matches the canonical MobileSheets database fixture (tests/fixtures/mobilesheets.db),
 *    detecting any schema drift (missing tables, mismatched columns/types, missing indexes, user_version).
 * 2. Freshly exported libraries (Path 2: created from scratch without prior import) produce a 100% compatible SQLite database.
 * 3. Standard MobileSheets startup and sync queries execute cleanly against fresh exports with zero SQL errors.
 */
final class MobileSheetsBaselineCompatibilityTest extends TestCase {
	use RealMobileSheetsTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	/**
	 * Test 1: Verify baseline schema constants match tests/fixtures/mobilesheets.db exactly.
	 * If MobileSheets library updates in the future and the fixture is updated, this test will
	 * fail immediately and report the exact diffs.
	 */
	public function testBaselineSchemaMatchesFixtureExactly(): void {
		$fixturePath = dirname(__DIR__, 3) . '/fixtures/mobilesheets.db';
		self::assertFileExists($fixturePath, 'MobileSheets reference fixture must exist');

		$fixtureDb = new PDO('sqlite:' . $fixturePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		// 1. Pragmas
		$fixtureUserVersion = (int)$fixtureDb->query('PRAGMA user_version')->fetchColumn();
		self::assertSame(
			$fixtureUserVersion,
			MobileSheetsBaselineSchema::DEFAULT_USER_VERSION,
			'Baseline DEFAULT_USER_VERSION must match fixture user_version'
		);

		$fixturePageSize = (int)$fixtureDb->query('PRAGMA page_size')->fetchColumn();
		self::assertSame(
			$fixturePageSize,
			MobileSheetsBaselineSchema::DEFAULT_PAGE_SIZE,
			'Baseline DEFAULT_PAGE_SIZE must match fixture page_size'
		);

		// 2. Tables
		$fixtureTableRows = $fixtureDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC")->fetchAll();
		$fixtureTableNames = array_column($fixtureTableRows, 'name');
		sort($fixtureTableNames);

		$baselineTableNames = array_keys(MobileSheetsBaselineSchema::TABLES);
		sort($baselineTableNames);

		self::assertSame($fixtureTableNames, $baselineTableNames, 'Baseline table list must match fixture table list exactly');

		// 3. Columns & Types per table
		foreach ($fixtureTableNames as $table) {
			$fixtureCols = $fixtureDb->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $table)))->fetchAll();
			$fixtureColMap = [];
			foreach ($fixtureCols as $col) {
				$fixtureColMap[(string)$col['name']] = [
					'type' => strtoupper((string)$col['type']),
					'notnull' => (int)$col['notnull'],
					'pk' => (int)$col['pk'],
				];
			}

			// Create table in a temporary in-memory database using baseline DDL
			$memDb = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
			$baselineDdl = MobileSheetsBaselineSchema::TABLES[$table];
			$memDb->exec($baselineDdl);

			$baselineCols = $memDb->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $table)))->fetchAll();
			$baselineColMap = [];
			foreach ($baselineCols as $col) {
				$baselineColMap[(string)$col['name']] = [
					'type' => strtoupper((string)$col['type']),
					'notnull' => (int)$col['notnull'],
					'pk' => (int)$col['pk'],
				];
			}

			self::assertSame(
				array_keys($fixtureColMap),
				array_keys($baselineColMap),
				sprintf('Table "%s" columns do not match between baseline and fixture', $table)
			);

			foreach ($fixtureColMap as $colName => $fixtureProps) {
				self::assertSame(
					$fixtureProps['type'],
					$baselineColMap[$colName]['type'],
					sprintf('Table "%s", column "%s" type mismatch', $table, $colName)
				);
				self::assertSame(
					$fixtureProps['pk'],
					$baselineColMap[$colName]['pk'],
					sprintf('Table "%s", column "%s" primary key flag mismatch', $table, $colName)
				);
			}
		}

		// 4. Indexes
		$fixtureIndexRows = $fixtureDb->query("SELECT name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC")->fetchAll();
		$fixtureIndexNames = array_column($fixtureIndexRows, 'name');
		sort($fixtureIndexNames);

		$baselineIndexNames = [];
		foreach (MobileSheetsBaselineSchema::INDEXES as $idxSql) {
			if (preg_match('/CREATE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z0-9_]+)/i', $idxSql, $m)) {
				$baselineIndexNames[] = $m[1];
			}
		}
		sort($baselineIndexNames);

		self::assertSame($fixtureIndexNames, $baselineIndexNames, 'Baseline indexes must match fixture indexes');
	}

	/**
	 * Test 2: Verify fresh library export (Path 2, without prior import) produces all baseline tables, indexes, and seeded data.
	 */
	public function testFreshLibraryExportProducesCompatibleDatabase(): void {
		$lib = $this->createTestLibrary('Fresh_Compat_' . bin2hex(random_bytes(3)));

		// Create a song
		$song = new InternalSong(
			uuid: 'fresh-song-compat-1',
			libraryId: $lib->id,
			title: 'Canon in D',
			sortTitle: 'Canon in D',
			rating: 4,
			difficulty: 3,
			duration: 300,
			notes: 'Play softly in beginning',
			createdAt: 1000,
			updatedAt: 1000,
		);
		$song = $this->songMapper->insert($song);
		$this->attachTestFileToSong($song, 'canon.pdf', clientId: 1);

		// Create entities of all supported types
		$entitySpecs = [
			['composer', 'Johann Pachelbel'],
			['artist', 'Chamber Ensemble'],
			['book', 'Baroque Favorites'],
			['collection', 'Wedding Songs'],
			['genre', 'Classical'],
			['key', 'D Major'],
			['customgroup', 'String Quartet'],
			['year', '1680'],
			['signature', '4/4'],
			['sourcetype', 'Original Sheet'],
		];

		foreach ($entitySpecs as [$type, $name]) {
			$ent = new InternalEntity(
				uuid: 'fresh-ent-' . $type . '-' . bin2hex(random_bytes(2)),
				libraryId: $lib->id,
				type: $type,
				name: $name,
				createdAt: 1000,
				updatedAt: 1000,
			);
			$this->entityMapper->insert($ent);
			$this->entityMapper->setSongEntitiesForType($song->id, $type, [$ent->id]);
		}

		// Export fresh library (Path 2: fallback to MobileSheetsBaselineSchema)
		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath);
		self::assertFileExists($exportPath);

		$db = new PDO('sqlite:' . $exportPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		// Verify PRAGMAs
		self::assertSame(63, (int)$db->query('PRAGMA user_version')->fetchColumn());
		self::assertSame(1024, (int)$db->query('PRAGMA page_size')->fetchColumn());

		// Verify table presence (60 baseline + _nc_sync_metadata)
		$exportedTables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
		foreach (array_keys(MobileSheetsBaselineSchema::TABLES) as $expectedTable) {
			self::assertContains($expectedTable, $exportedTables, sprintf('Exported DB must contain table %s', $expectedTable));
		}
		self::assertContains('_nc_sync_metadata', $exportedTables);

		// Verify all baseline indexes exist
		$exportedIndexes = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
		foreach (MobileSheetsBaselineSchema::INDEXES as $idxSql) {
			if (preg_match('/CREATE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z0-9_]+)/i', $idxSql, $m)) {
				self::assertContains($m[1], $exportedIndexes, sprintf('Exported DB must contain index %s', $m[1]));
			}
		}

		// Verify seeded metadata
		$locale = $db->query('SELECT locale FROM android_metadata')->fetchColumn();
		self::assertSame('en_US', $locale);

		$initialized = (int)$db->query('SELECT Initialized FROM Defaults WHERE Id = 1')->fetchColumn();
		self::assertSame(1, $initialized);

		// Verify exported song
		$songRow = $db->query('SELECT * FROM Songs WHERE Id = 1')->fetch();
		self::assertIsArray($songRow);
		self::assertSame('Canon in D', $songRow['Title']);
		self::assertSame(4, (int)$songRow['Stars']);
		self::assertSame(3, (int)$songRow['Difficulty']);
		self::assertSame(300, (int)$songRow['Duration']);

		// Verify SongNotes uses native 'Notes' column
		$noteRow = $db->query('SELECT * FROM SongNotes WHERE SongId = 1')->fetch();
		self::assertIsArray($noteRow);
		self::assertSame('Play softly in beginning', $noteRow['Notes']);

		// Verify Key and SourceType use exact native table names
		$keyRow = $db->query('SELECT * FROM "Key" WHERE Name = \'D Major\'')->fetch();
		self::assertIsArray($keyRow);
		self::assertSame('D Major', $keyRow['Name']);

		$keyLink = $db->query(sprintf('SELECT * FROM KeySongs WHERE KeyId = %d AND SongId = %d', (int)$keyRow['Id'], (int)$songRow['Id']))->fetch();
		self::assertIsArray($keyLink);

		$sourceRow = $db->query('SELECT * FROM SourceType WHERE Type = \'Original Sheet\'')->fetch();
		self::assertIsArray($sourceRow);
		self::assertSame('Original Sheet', $sourceRow['Type']);

		$sourceLink = $db->query(sprintf('SELECT * FROM SourceTypeSongs WHERE SourceTypeId = %d AND SongId = %d', (int)$sourceRow['Id'], (int)$songRow['Id']))->fetch();
		self::assertIsArray($sourceLink);

		// Integrity check
		$check = $db->query('PRAGMA quick_check;')->fetchColumn();
		self::assertSame('ok', $check);
	}

	/**
	 * Test 3: Simulate MobileSheets app startup and sync queries on fresh export.
	 * Any missing table or column error would cause "Failed to open folder database" on client.
	 */
	public function testFreshExportExecutesMobileSheetsStandardQueries(): void {
		$lib = $this->createTestLibrary('Fresh_Queries_' . bin2hex(random_bytes(3)));

		$song = new InternalSong(
			uuid: 'query-test-song-1',
			libraryId: $lib->id,
			title: 'Clair de Lune',
			sortTitle: 'Clair de Lune',
			rating: 5,
			difficulty: 4,
			duration: 330,
			notes: 'Andante très expressif',
			createdAt: 2000,
			updatedAt: 2000,
		);
		$song = $this->songMapper->insert($song);
		$this->attachTestFileToSong($song, 'canon.pdf', clientId: 1);

		$exportPath = $this->tempDbPath();
		$this->exporter->exportToFile($lib, $exportPath);

		$db = new PDO('sqlite:' . $exportPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		// Standard queries executed by MobileSheets upon library open and song list loading
		$mobileSheetsQueries = [
			'PRAGMA user_version;',
			'PRAGMA page_size;',
			'PRAGMA quick_check;',
			'SELECT locale FROM android_metadata LIMIT 1;',
			'SELECT Initialized FROM Defaults WHERE Id = 1;',
			'SELECT Id, Title, SortTitle, Difficulty, Duration, Stars, CreationDate, LastModified, Keywords, Custom, Custom2 FROM Songs;',
			'SELECT Id, SongId, Notes, ShowNotesOnLoad, DisplayTime, TextSize, Alignment FROM SongNotes;',
			'SELECT Id, Name, SortBy, Ascending, DateCreated, LastModified FROM "Key";',
			'SELECT Id, Type, SortBy, Ascending, DateCreated, LastModified FROM SourceType;',
			'SELECT Id, Name, SortBy, Ascending, DateCreated, LastModified FROM Composer;',
			'SELECT Id, Name, SortBy, Ascending, DateCreated, LastModified FROM Artists;',
			'SELECT Id, Title, SortBy, Ascending, DateCreated, LastModified FROM Books;',
			'SELECT Id, Name, SortBy, Ascending, DateCreated, LastModified FROM Collections;',
			'SELECT Id, Type, SortBy, Ascending, DateCreated, LastModified FROM Genres;',
			'SELECT Id, Name, SortBy, Ascending, DateCreated, LastModified FROM CustomGroup;',
			'SELECT Id, Name, SortBy, Ascending, DateCreated, LastModified FROM Years;',
			'SELECT Id, Name, SortBy, Ascending, DateCreated, LastModified FROM Signature;',
			'SELECT ComposerId, SongId FROM ComposerSongs;',
			'SELECT ArtistId, SongId FROM ArtistsSongs;',
			'SELECT KeyId, SongId FROM KeySongs;',
			'SELECT SourceTypeId, SongId FROM SourceTypeSongs;',
			'SELECT GroupId, SongId FROM CustomGroupSongs;',
			'SELECT Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, FileHash FROM Files;',
			'SELECT Id, SongId, Title, File, FullDuration, Volume FROM AudioFiles;',
			'SELECT Id, SongId, Page, Type, Opacity, Zoom, ZoomY, Version FROM AnnotationsBase;',
			'SELECT Id, AnnotationId, Points, Count FROM AnnotationPoints;',
			'SELECT Id, BaseId, LineColor, FillColor, LineWidth, DrawMode FROM DrawAnnotations;',
			'SELECT Id, SongId, Page, LayerIndex, Visible FROM Layers;',
			'SELECT Id, SongId, StartPointX, StartPointY, EndPointX, EndPointY, StartPage, EndPage FROM Links;',
			'SELECT Id, SongId, CommandType, Cable, Channel, MSB, LSB, Value FROM MIDI;',
			'SELECT Id, SongId, Sig1, Sig2, Subdivision, SoundFX, AutoStart FROM MetronomeSettings;',
			'SELECT Id, SongId, Behavior, Speed, FixedDuration FROM AutoScroll;',
			'SELECT Id, SongId, Page, Left, Top, Right, Bottom, Rotation FROM Crop;',
			'SELECT Id, SongId, Name, PageNum, ShowInLibrary FROM Bookmarks;',
		];

		foreach ($mobileSheetsQueries as $query) {
			$stmt = $db->query($query);
			self::assertNotFalse($stmt, sprintf('Query failed to execute: %s', $query));
			$stmt->fetchAll();
		}
	}
}
