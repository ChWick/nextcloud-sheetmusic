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

class Version1002Date20261009120000 extends SimpleMigrationStep {
	public function __construct(
		private ?IDBConnection $db = null,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// 1. sheetmusic_libraries
		if (!$schema->hasTable('sheetmusic_libraries')) {
			$table = $schema->createTable('sheetmusic_libraries');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('path', Types::STRING, [
				'length' => 4000,
				'notnull' => true,
			]);
			$table->addColumn('folder_id', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('auto_paths', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
			$table->addColumn('auto_pdf_metadata', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
			$table->addColumn('last_sync_at', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'sheetmusic_lib_user_idx');
			$table->addIndex(['folder_id'], 'sheetmusic_lib_folder_idx');
		}

		// 2. sheetmusic_songs
		if (!$schema->hasTable('sheetmusic_songs')) {
			$table = $schema->createTable('sheetmusic_songs');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('uuid', Types::STRING, [
				'length' => 36,
				'notnull' => true,
			]);
			$table->addColumn('library_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('title', Types::STRING, [
				'length' => 255,
				'notnull' => true,
			]);
			$table->addColumn('sort_title', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('rating', Types::SMALLINT, [
				'notnull' => false,
			]);
			$table->addColumn('difficulty', Types::SMALLINT, [
				'notnull' => false,
			]);
			$table->addColumn('duration', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('keywords', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('notes', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('opus', Types::STRING, [
				'length' => 64,
				'notnull' => false,
			]);
			$table->addColumn('catalog_number', Types::STRING, [
				'length' => 64,
				'notnull' => false,
			]);
			$table->addColumn('song_number', Types::STRING, [
				'length' => 64,
				'notnull' => false,
			]);
			$table->addColumn('client_id', Types::INTEGER, [
				'notnull' => false,
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
			$table->addUniqueIndex(['uuid'], 'sheetmusic_song_uuid_idx');
			$table->addIndex(['library_id'], 'sheetmusic_song_lib_idx');
			$table->addIndex(['library_id', 'deleted_at'], 'sheetmusic_song_del_idx');
		}

		// 3. sheetmusic_song_attributes
		if (!$schema->hasTable('sheetmusic_song_attributes')) {
			$table = $schema->createTable('sheetmusic_song_attributes');
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
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('value', Types::TEXT, [
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
			$table->addIndex(['song_id'], 'sheetmusic_attr_song_idx');
			$table->addIndex(['song_id', 'name'], 'sheetmusic_attr_name_idx');
		}

		// 4. sheetmusic_entities
		if (!$schema->hasTable('sheetmusic_entities')) {
			$table = $schema->createTable('sheetmusic_entities');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('uuid', Types::STRING, [
				'length' => 36,
				'notnull' => true,
			]);
			$table->addColumn('library_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('type', Types::STRING, [
				'length' => 32,
				'notnull' => true,
			]);
			$table->addColumn('name', Types::STRING, [
				'length' => 255,
				'notnull' => true,
			]);
			$table->addColumn('sort_by', Types::SMALLINT, [
				'notnull' => false,
				'default' => 1,
			]);
			$table->addColumn('ascending', Types::SMALLINT, [
				'notnull' => false,
				'default' => 1,
			]);
			$table->addColumn('client_id', Types::INTEGER, [
				'notnull' => false,
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
			$table->addUniqueIndex(['uuid'], 'sheetmusic_ent_uuid_idx');
			$table->addIndex(['library_id', 'type'], 'sheetmusic_ent_lib_type_idx');
			$table->addIndex(['library_id', 'type', 'name'], 'sheetmusic_ent_lookup_idx');
		}

		// 5. sheetmusic_song_entities
		if (!$schema->hasTable('sheetmusic_song_entities')) {
			$table = $schema->createTable('sheetmusic_song_entities');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('song_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('entity_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('deleted_at', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['song_id'], 'sheetmusic_se_song_idx');
			$table->addIndex(['entity_id'], 'sheetmusic_se_entity_idx');
			$table->addIndex(['song_id', 'entity_id', 'deleted_at'], 'sheetmusic_se_pair_idx');
		}

		// 6. sheetmusic_song_files
		if (!$schema->hasTable('sheetmusic_song_files')) {
			$table = $schema->createTable('sheetmusic_song_files');
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
			$table->addColumn('page_order', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('page_count', Types::INTEGER, [
				'notnull' => false,
				'default' => 0,
			]);
			$table->addColumn('file_size', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('file_hash', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('type', Types::SMALLINT, [
				'notnull' => false,
				'default' => 0,
			]);
			$table->addColumn('client_id', Types::INTEGER, [
				'notnull' => false,
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
			$table->addUniqueIndex(['uuid'], 'sheetmusic_sf_uuid_idx');
			$table->addIndex(['song_id'], 'sheetmusic_sf_song_idx');
			$table->addIndex(['file_id'], 'sheetmusic_sf_file_idx');
		}

		// 7. sheetmusic_misc_schema
		if (!$schema->hasTable('sheetmusic_misc_schema')) {
			$table = $schema->createTable('sheetmusic_misc_schema');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('library_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('client_type', Types::STRING, [
				'length' => 32,
				'notnull' => true,
			]);
			$table->addColumn('table_name', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('create_sql', Types::TEXT, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['library_id', 'client_type'], 'sheetmusic_ms_lib_client_idx');
			$table->addIndex(['library_id', 'table_name'], 'sheetmusic_ms_lib_table_idx');
		}

		// 8. sheetmusic_misc_data
		if (!$schema->hasTable('sheetmusic_misc_data')) {
			$table = $schema->createTable('sheetmusic_misc_data');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('library_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('client_type', Types::STRING, [
				'length' => 32,
				'notnull' => true,
			]);
			$table->addColumn('table_name', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('record_id', Types::STRING, [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('song_uuid', Types::STRING, [
				'length' => 36,
				'notnull' => false,
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
			$table->addIndex(['library_id', 'table_name'], 'sheetmusic_md_lib_table_idx');
			$table->addIndex(['song_uuid'], 'sheetmusic_md_song_uuid_idx');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->db === null) {
			return;
		}

		// Migrate any existing library rows from legacy mobilesheets_libraries to sheetmusic_libraries
		try {
			if ($this->db->tableExists('mobilesheets_libraries') && $this->db->tableExists('sheetmusic_libraries')) {
				$qbCheck = $this->db->getQueryBuilder();
				$qbCheck->select('id')->from('sheetmusic_libraries')->setMaxResults(1);
				$existingCount = (int)$qbCheck->executeQuery()->rowCount();

				if ($existingCount === 0) {
					$now = time();
					$qbLegacy = $this->db->getQueryBuilder();
					$qbLegacy->select('*')->from('mobilesheets_libraries');
					$rows = $qbLegacy->executeQuery()->fetchAllAssociative();

					foreach ($rows as $row) {
						$qbInsert = $this->db->getQueryBuilder();
						$qbInsert->insert('sheetmusic_libraries')
							->values([
								'id' => $qbInsert->createNamedParameter((int)$row['id']),
								'user_id' => $qbInsert->createNamedParameter((string)$row['user_id']),
								'path' => $qbInsert->createNamedParameter((string)$row['path']),
								'folder_id' => $qbInsert->createNamedParameter(null),
								'auto_paths' => $qbInsert->createNamedParameter(isset($row['auto_paths']) ? (bool)$row['auto_paths'] : true),
								'auto_pdf_metadata' => $qbInsert->createNamedParameter(isset($row['auto_pdf_metadata']) ? (bool)$row['auto_pdf_metadata'] : true),
								'last_sync_at' => $qbInsert->createNamedParameter(null),
								'created_at' => $qbInsert->createNamedParameter($now),
								'updated_at' => $qbInsert->createNamedParameter($now),
							]);
						$qbInsert->executeStatement();
					}
				}
			}
		} catch (\Throwable) {
			// Ignore if tables cannot be queried yet
		}
	}
}
