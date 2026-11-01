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
use OCA\SheetMusic\Migration\Version1004Date20261101120000;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class Version1004Test extends TestCase {
	use RealMobileSheetsTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testChangeSchemaCreatesBookmarksTable(): void {
		$output = new NullOutput();
		$connection = \OC::$server->get(\OC\DB\Connection::class);
		$schema = new SchemaWrapper($connection, new Schema());

		$migration = new Version1004Date20261101120000();
		$res = $migration->changeSchema($output, fn () => $schema, []);

		self::assertSame($schema, $res);
		self::assertTrue($schema->hasTable('sheetmusic_bookmarks'));
		$table = $schema->getTable('sheetmusic_bookmarks');
		self::assertTrue($table->hasColumn('id'));
		self::assertTrue($table->hasColumn('song_id'));
		self::assertTrue($table->hasColumn('name'));
		self::assertTrue($table->hasColumn('page_num'));
		self::assertTrue($table->hasColumn('end_page'));
		self::assertTrue($table->hasColumn('position'));
		self::assertTrue($table->hasColumn('show_in_library'));
		self::assertTrue($table->hasColumn('created_at'));
		self::assertTrue($table->hasColumn('updated_at'));
	}
}
