<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Migration\Version1000Date20260918120000;
use OCA\SheetMusic\Migration\Version1001Date20261005120000;
use OCA\SheetMusic\Migration\Version1002Date20261009120000;
use OCA\SheetMusic\Service\MobileSheetsPathResolver;
use OCA\SheetMusic\Service\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Service\MobileSheetsSqliteImporter;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MobileSheetsRoundtripTest extends TestCase {
	private string $exportedDbPath;
	private string $fixturePath;
	private ?int $libraryId = null;
	private ?string $testUser = null;
	private ?\OCP\IDBConnection $dbConn = null;

	protected function setUp(): void {
		parent::setUp();
		$this->fixturePath = dirname(__DIR__, 2) . '/fixtures/mobilesheets.db';
		$this->exportedDbPath = tempnam(sys_get_temp_dir(), 'exported_ms_') . '.db';
	}

	protected function tearDown(): void {
		if ($this->libraryId !== null && $this->dbConn !== null) {
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_entities WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$this->libraryId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_attributes WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$this->libraryId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_files WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$this->libraryId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_songs WHERE library_id = ?', [$this->libraryId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_entities WHERE library_id = ?', [$this->libraryId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_misc_data WHERE library_id = ?', [$this->libraryId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_misc_schema WHERE library_id = ?', [$this->libraryId]);
			$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_libraries WHERE id = ?', [$this->libraryId]);
		}
		if (file_exists($this->exportedDbPath)) {
			@unlink($this->exportedDbPath);
		}
		parent::tearDown();
	}

	public function testImportAndExportProducesIdenticalDatabase(): void {
		self::assertFileExists($this->fixturePath);

		$this->dbConn = \OC::$server->get(\OCP\IDBConnection::class);

		// Mappers and Services
		$songMapper = new InternalSongMapper($this->dbConn);
		$entityMapper = new InternalEntityMapper($this->dbConn);
		$songFileMapper = new InternalSongFileMapper($this->dbConn);
		$attributeMapper = new InternalSongAttributeMapper($this->dbConn);
		$miscMapper = new InternalMiscMapper($this->dbConn);
		$libraryMapper = new LibraryMapper($this->dbConn);
		$rootFolder = \OC::$server->get(IRootFolder::class);
		$userManager = \OC::$server->get(IUserManager::class);
		$logger = new NullLogger();
		$pathResolver = new MobileSheetsPathResolver($rootFolder);

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

		$this->testUser = 'roundtrip_' . bin2hex(random_bytes(4));
		$libraryMapper->addForUser($this->testUser, 'SheetMusic');
		$libs = $libraryMapper->findAllForUser($this->testUser);
		$library = $libraryMapper->findModelById((int)$libs[0]['id']);
		self::assertNotNull($library);
		$this->libraryId = (int)$library->id;

		// 1. Import fixture
		$stats = $importer->importFromFile($library, $this->fixturePath);
		self::assertSame(422, $stats['songsCreated']);
		self::assertSame(268, $stats['entitiesCreated']);
		self::assertSame(15, $stats['miscTablesSynced']);

		// 2. Export database
		$exporter->exportToFile($library, $this->exportedDbPath);
		self::assertFileExists($this->exportedDbPath);

		// 5. Compare original and exported databases
		$origDb = new PDO('sqlite:' . $this->fixturePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$expDb = new PDO('sqlite:' . $this->exportedDbPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$origTables = $origDb->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
		$expTables = $expDb->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();

		$origTableNames = array_column($origTables, 'name');
		$expTableNames = array_column($expTables, 'name');

		self::assertSame($origTableNames, $expTableNames, 'Table list must match exactly');

		$origSql = array_column($origTables, 'sql', 'name');
		$expSql = array_column($expTables, 'sql', 'name');

		foreach ($origSql as $name => $sql) {
			$s1 = preg_replace('/\s+/', ' ', trim($sql));
			$s2 = preg_replace('/\s+/', ' ', trim($expSql[$name] ?? ''));
			self::assertSame($s1, $s2, "Schema mismatch for table '$name'");
		}

		foreach ($origTableNames as $table) {
			$rOrig = $origDb->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"')->fetchAll();
			$rExp = $expDb->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"')->fetchAll();

			self::assertCount(count($rOrig), $rExp, "Row count mismatch in table '$table'");

			foreach ($rOrig as $idx => $row) {
				$expRow = $rExp[$idx] ?? null;
				self::assertNotNull($expRow, "Missing row $idx in $table");

				foreach ($row as $k => $v) {
					$ev = $expRow[$k] ?? null;
					if (is_numeric($v) && is_numeric($ev) && (float)$v === (float)$ev) {
						continue;
					}
					self::assertSame($v, $ev, "Mismatch in table '$table', row $idx, column '$k'");
				}
			}
		}
	}
}
