<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use PDO;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsRoundtripTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private string $exportedDbPath;
	private string $fixturePath;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->fixturePath = dirname(__DIR__, 2) . '/fixtures/mobilesheets.db';
		$this->exportedDbPath = $this->tempDbPath();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testImportAndExportProducesIdenticalDatabase(): void {
		self::assertFileExists($this->fixturePath);

		$library = $this->createTestLibrary('SheetMusic');

		// 1. Import fixture
		$stats = $this->importer->importFromFile($library, $this->fixturePath);
		self::assertSame(422, $stats['songsCreated']);
		self::assertSame(268, $stats['entitiesCreated']);
		self::assertSame(15, $stats['miscTablesSynced']);

		// 2. Export database
		$this->exporter->exportToFile($library, $this->exportedDbPath);
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
