<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1011Date20261117120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sheetmusic_annotations')) {
			$table = $schema->getTable('sheetmusic_annotations');
			if ($table->hasColumn('song_id')) {
				$table->getColumn('song_id')->setDefault(0);
			}
			if (!$table->hasIndex('sm_ann_file_page_idx')) {
				$table->addIndex(['file_id', 'page_number'], 'sm_ann_file_page_idx');
			}
			if (!$table->hasIndex('sm_ann_file_del_idx')) {
				$table->addIndex(['file_id', 'deleted_at'], 'sm_ann_file_del_idx');
			}
		}

		return $schema;
	}
}
