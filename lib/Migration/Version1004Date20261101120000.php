<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1004Date20261101120000 extends SimpleMigrationStep {
	public function __construct(
		private ?IDBConnection $db = null,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sheetmusic_bookmarks')) {
			$table = $schema->createTable('sheetmusic_bookmarks');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('song_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('name', Types::STRING, [
				'length' => 255,
				'notnull' => true,
			]);
			$table->addColumn('page_num', Types::INTEGER, [
				'notnull' => true,
				'default' => 1,
			]);
			$table->addColumn('end_page', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('position', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('show_in_library', Types::SMALLINT, [
				'notnull' => true,
				'default' => 1,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['song_id', 'position'], 'sheetmusic_bm_song_pos_idx');
			$table->addIndex(['song_id'], 'sheetmusic_bm_song_idx');
		}

		return $schema;
	}
}
