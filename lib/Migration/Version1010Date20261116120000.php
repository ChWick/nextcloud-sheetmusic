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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1010Date20261116120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sheetmusic_setlist_files')) {
			$table = $schema->createTable('sheetmusic_setlist_files');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('setlist_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('library_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('oc_file_id', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('path', Types::STRING, [
				'length' => 1024,
				'notnull' => true,
			]);
			$table->addColumn('filename', Types::STRING, [
				'length' => 255,
				'notnull' => true,
			]);
			$table->addColumn('mime_type', Types::STRING, [
				'length' => 128,
				'notnull' => true,
				'default' => '',
			]);
			$table->addColumn('file_size', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('sort_order', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['setlist_id'], 'sm_setlist_files_setlist_idx');
			$table->addIndex(['library_id'], 'sm_setlist_files_library_idx');
		}

		return $schema;
	}
}
