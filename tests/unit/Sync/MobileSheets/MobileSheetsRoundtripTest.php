<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TestSupport\MobileSheetsDatabasePresets;
use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsRoundtripTest extends TestCase {
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
	 * @return array<string, array{string, callable(): string}>
	 */
	public static function databasePresetProvider(): array {
		return MobileSheetsDatabasePresets::allPresets();
	}

	#[DataProvider('databasePresetProvider')]
	public function testRoundtripWithVariousDatabasesAndSettings(string $description, callable $dbGenerator): void {
		$sourceDbPath = $dbGenerator();
		self::assertFileExists($sourceDbPath, "Source database file must exist: $description");
		if (str_starts_with($sourceDbPath, sys_get_temp_dir())) {
			$this->tempFiles[] = $sourceDbPath;
		}

		$library = $this->createTestLibrary('Roundtrip_' . bin2hex(random_bytes(3)));

		// 1. Import database into Nextcloud
		$stats = $this->importer->importFromFile($library, $sourceDbPath);
		self::assertIsArray($stats);

		// 2. Export database back to MobileSheets SQLite format
		$exportedDbPath = $this->tempDbPath();
		$this->exporter->exportToFile($library, $exportedDbPath);
		self::assertFileExists($exportedDbPath);

		// 3. Verify original and exported databases are 100% identical in schema and content
		$this->assertDatabasesAreIdentical($sourceDbPath, $exportedDbPath, $description);
	}

	private function assertDatabasesAreIdentical(string $origPath, string $expPath, string $description): void {
		$origDb = new PDO('sqlite:' . $origPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$expDb = new PDO('sqlite:' . $expPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$origTables = $origDb->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
		$expTables = $expDb->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();

		$origTableNames = array_column($origTables, 'name');
		$expTableNames = array_column($expTables, 'name');

		if (!in_array('_nc_sync_metadata', $origTableNames, true)) {
			$expTables = array_values(array_filter($expTables, fn ($t) => $t['name'] !== '_nc_sync_metadata'));
			$expTableNames = array_values(array_filter($expTableNames, fn ($name) => $name !== '_nc_sync_metadata'));
		}

		self::assertSame($origTableNames, $expTableNames, "Table list must match exactly for preset: $description");

		$origUserVersion = (int)$origDb->query('PRAGMA user_version')->fetchColumn();
		$expUserVersion = (int)$expDb->query('PRAGMA user_version')->fetchColumn();
		self::assertSame($origUserVersion, $expUserVersion, "SQLite user_version must match for preset: $description");

		$origSql = array_column($origTables, 'sql', 'name');
		$expSql = array_column($expTables, 'sql', 'name');

		foreach ($origSql as $name => $sql) {
			$s1 = preg_replace('/\s+/', ' ', trim($sql));
			$s2 = preg_replace('/\s+/', ' ', trim($expSql[$name] ?? ''));
			self::assertSame($s1, $s2, "Schema mismatch for table '$name' in preset: $description");
		}

		foreach ($origTableNames as $table) {
			$rOrig = $origDb->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"')->fetchAll();
			$rExp = $expDb->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"')->fetchAll();

			self::assertCount(count($rOrig), $rExp, "Row count mismatch in table '$table' for preset: $description");

			foreach ($rOrig as $idx => $row) {
				$expRow = $rExp[$idx] ?? null;
				self::assertNotNull($expRow, "Missing row $idx in $table for preset: $description");

				foreach ($row as $k => $v) {
					$ev = $expRow[$k] ?? null;
					if (is_numeric($v) && is_numeric($ev) && (float)$v === (float)$ev) {
						continue;
					}
					self::assertSame($v, $ev, "Mismatch in table '$table', row $idx, column '$k' for preset: $description");
				}
			}
		}
	}
}
