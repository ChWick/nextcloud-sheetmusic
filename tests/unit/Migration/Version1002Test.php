<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Migration;

use Doctrine\DBAL\Schema\Schema;
use OC\DB\SchemaWrapper;
use OC\Migration\NullOutput;
use OCA\SheetMusic\Migration\Version1002Date20261009120000;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class Version1002Test extends TestCase {
	use RealMobileSheetsTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testChangeSchemaCreatesAllInternalTables(): void {
		$output = new NullOutput();
		$connection = \OC::$server->get(\OC\DB\Connection::class);
		$schema = new SchemaWrapper($connection, new Schema());

		$migration = new Version1002Date20261009120000();
		$res = $migration->changeSchema($output, fn () => $schema, []);

		self::assertSame($schema, $res);
		self::assertTrue($schema->hasTable('sheetmusic_libraries'));
		self::assertTrue($schema->hasTable('sheetmusic_songs'));
		self::assertTrue($schema->hasTable('sheetmusic_song_attributes'));
		self::assertTrue($schema->hasTable('sheetmusic_entities'));
		self::assertTrue($schema->hasTable('sheetmusic_song_entities'));
		self::assertTrue($schema->hasTable('sheetmusic_song_files'));
		self::assertTrue($schema->hasTable('sheetmusic_misc_schema'));
		self::assertTrue($schema->hasTable('sheetmusic_misc_data'));
	}
}
