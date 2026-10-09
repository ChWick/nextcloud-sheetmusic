<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Migration;

use OCA\SheetMusic\Migration\Version1002Date20261009120000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class Version1002Test extends TestCase {
	public function testChangeSchemaCreatesAllInternalTables(): void {
		$output = $this->createMock(IOutput::class);
		$schema = $this->createMock(ISchemaWrapper::class);

		$createdTables = [];
		$schema->method('hasTable')->willReturn(false);
		$schema->method('createTable')->willReturnCallback(function (string $name) use (&$createdTables) {
			$createdTables[] = $name;
			$table = $this->createMock(\OCP\DB\Schema\ITable::class);
			$table->method('addColumn')->willReturn($this->createMock(\OCP\DB\Schema\IColumn::class));
			$table->method('setPrimaryKey')->willReturnSelf();
			$table->method('addIndex')->willReturnSelf();
			$table->method('addUniqueIndex')->willReturnSelf();
			return $table;
		});

		$migration = new Version1002Date20261009120000();
		$res = $migration->changeSchema($output, fn () => $schema, []);

		self::assertSame($schema, $res);
		self::assertContains('sheetmusic_libraries', $createdTables);
		self::assertContains('sheetmusic_songs', $createdTables);
		self::assertContains('sheetmusic_song_attributes', $createdTables);
		self::assertContains('sheetmusic_entities', $createdTables);
		self::assertContains('sheetmusic_song_entities', $createdTables);
		self::assertContains('sheetmusic_song_files', $createdTables);
		self::assertContains('sheetmusic_misc_schema', $createdTables);
		self::assertContains('sheetmusic_misc_data', $createdTables);
	}
}
