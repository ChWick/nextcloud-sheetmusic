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

class Version1005Date20261102120000 extends SimpleMigrationStep {
	public function __construct(
		private ?IDBConnection $db = null,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sheetmusic_setlist_items')) {
			$table = $schema->createTable('sheetmusic_setlist_items');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('setlist_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('song_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('bookmark_id', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('position', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
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
			$table->addIndex(['setlist_id', 'position'], 'sheetmusic_si_setlist_pos_idx');
			$table->addIndex(['setlist_id'], 'sheetmusic_si_setlist_idx');
			$table->addIndex(['song_id'], 'sheetmusic_si_song_idx');
			$table->addIndex(['bookmark_id'], 'sheetmusic_si_bm_idx');
		}

		return $schema;
	}
}
