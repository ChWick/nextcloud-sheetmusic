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

class Version1006Date20261106120000 extends SimpleMigrationStep {
	public function __construct(
		private ?IDBConnection $db = null,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sheetmusic_annotations')) {
			$table = $schema->createTable('sheetmusic_annotations');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('uuid', Types::STRING, [
				'length' => 36,
				'notnull' => true,
			]);
			$table->addColumn('song_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('page_number', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('layer_index', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('type', Types::STRING, [
				'length' => 32,
				'notnull' => true,
			]);
			$table->addColumn('x', Types::FLOAT, [
				'notnull' => true,
				'default' => 0.0,
			]);
			$table->addColumn('y', Types::FLOAT, [
				'notnull' => true,
				'default' => 0.0,
			]);
			$table->addColumn('width', Types::FLOAT, [
				'notnull' => true,
				'default' => 0.0,
			]);
			$table->addColumn('height', Types::FLOAT, [
				'notnull' => true,
				'default' => 0.0,
			]);
			$table->addColumn('style_json', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('data_json', Types::TEXT, [
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
			$table->addColumn('deleted_at', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'sm_ann_uuid_idx');
			$table->addIndex(['song_id', 'file_id', 'page_number'], 'sm_ann_song_file_page_idx');
			$table->addIndex(['song_id', 'deleted_at'], 'sm_ann_song_del_idx');
		}

		if (!$schema->hasTable('sheetmusic_annotation_sync')) {
			$table = $schema->createTable('sheetmusic_annotation_sync');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('annotation_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('client_type', Types::STRING, [
				'length' => 32,
				'notnull' => true,
			]);
			$table->addColumn('client_record_id', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['client_type', 'client_record_id'], 'sm_ann_sync_client_idx');
			$table->addIndex(['annotation_id'], 'sm_ann_sync_ann_idx');
		}

		return $schema;
	}
}
