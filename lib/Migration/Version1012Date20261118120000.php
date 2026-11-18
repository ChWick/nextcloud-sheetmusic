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

class Version1012Date20261118120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sheetmusic_libraries')) {
			$table = $schema->getTable('sheetmusic_libraries');
			if (!$table->hasColumn('mobilesheets_sync_enabled')) {
				$table->addColumn('mobilesheets_sync_enabled', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
			}
		}

		return $schema;
	}
}
