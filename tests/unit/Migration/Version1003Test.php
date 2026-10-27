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
use OCA\SheetMusic\Migration\Version1003Date20261027120000;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class Version1003Test extends TestCase {
	use RealMobileSheetsTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testChangeSchemaCreatesSongHistoryTable(): void {
		$output = new NullOutput();
		$connection = \OC::$server->get(\OC\DB\Connection::class);
		$schema = new SchemaWrapper($connection, new Schema());

		$migration = new Version1003Date20261027120000();
		$res = $migration->changeSchema($output, fn () => $schema, []);

		self::assertSame($schema, $res);
		self::assertTrue($schema->hasTable('sheetmusic_song_history'));
		$table = $schema->getTable('sheetmusic_song_history');
		self::assertTrue($table->hasColumn('id'));
		self::assertTrue($table->hasColumn('library_id'));
		self::assertTrue($table->hasColumn('song_id'));
		self::assertTrue($table->hasColumn('song_uuid'));
		self::assertTrue($table->hasColumn('user_id'));
		self::assertTrue($table->hasColumn('action'));
		self::assertTrue($table->hasColumn('summary'));
		self::assertTrue($table->hasColumn('snapshot'));
		self::assertTrue($table->hasColumn('created_at'));
	}
}
