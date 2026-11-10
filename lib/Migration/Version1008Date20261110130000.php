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

class Version1008Date20261110130000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sheetmusic_sublibraries')) {
			$table = $schema->getTable('sheetmusic_sublibraries');
			if (!$table->hasColumn('folder_id')) {
				$table->addColumn('folder_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
				$table->addIndex(['folder_id'], 'sm_sublib_folder_idx');
			}
		}

		return $schema;
	}
}
