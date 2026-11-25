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

class Version1013Date20261125120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sheetmusic_song_files')) {
			$table = $schema->getTable('sheetmusic_song_files');
			if (!$table->hasColumn('crop_boxes')) {
				$table->addColumn('crop_boxes', Types::TEXT, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('crop_boxes_hash')) {
				$table->addColumn('crop_boxes_hash', Types::BIGINT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
