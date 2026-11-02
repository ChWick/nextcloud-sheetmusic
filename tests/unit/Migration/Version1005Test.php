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
use OCA\SheetMusic\Migration\Version1005Date20261102120000;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class Version1005Test extends TestCase {
	use RealMobileSheetsTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testChangeSchemaCreatesSetlistItemsTable(): void {
		$output = new NullOutput();
		$connection = \OC::$server->get(\OC\DB\Connection::class);
		$schema = new SchemaWrapper($connection, new Schema());

		$migration = new Version1005Date20261102120000();
		$res = $migration->changeSchema($output, fn () => $schema, []);

		self::assertSame($schema, $res);
		self::assertTrue($schema->hasTable('sheetmusic_setlist_items'));
		$table = $schema->getTable('sheetmusic_setlist_items');
		self::assertTrue($table->hasColumn('id'));
		self::assertTrue($table->hasColumn('setlist_id'));
		self::assertTrue($table->hasColumn('song_id'));
		self::assertTrue($table->hasColumn('bookmark_id'));
		self::assertTrue($table->hasColumn('position'));
		self::assertTrue($table->hasColumn('created_at'));
		self::assertTrue($table->hasColumn('updated_at'));
	}
}
