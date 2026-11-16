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

class Version1003Date20261027120000 extends SimpleMigrationStep {
	public function __construct(
		private ?IDBConnection $db = null,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sheetmusic_song_history')) {
			$table = $schema->createTable('sheetmusic_song_history');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('library_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('song_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('song_uuid', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'length' => 64,
				'notnull' => false,
			]);
			$table->addColumn('action', Types::STRING, [
				'length' => 32,
				'notnull' => true,
			]);
			$table->addColumn('summary', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('snapshot', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id'], 'sm_sh_pk');
			$table->addIndex(['song_id', 'created_at'], 'sheetmusic_sh_song_idx');
			$table->addIndex(['song_uuid', 'created_at'], 'sheetmusic_sh_uuid_idx');
			$table->addIndex(['library_id', 'created_at'], 'sheetmusic_sh_lib_idx');
			$table->addIndex(['library_id', 'action'], 'sheetmusic_sh_act_idx');
		}

		return $schema;
	}
}
