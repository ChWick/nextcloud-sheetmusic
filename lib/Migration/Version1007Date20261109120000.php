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

class Version1007Date20261109120000 extends SimpleMigrationStep {
	public function __construct(
		private ?IDBConnection $db = null,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sheetmusic_sublibraries')) {
			$table = $schema->createTable('sheetmusic_sublibraries');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('library_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('name', Types::STRING, [
				'length' => 128,
				'notnull' => true,
			]);
			$table->addColumn('slug', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('filters_json', Types::TEXT, [
				'notnull' => true,
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
			$table->addUniqueIndex(['library_id', 'slug'], 'sm_sublib_slug_idx');
			$table->addIndex(['library_id'], 'sm_sublib_lib_idx');
		}

		return $schema;
	}
}
