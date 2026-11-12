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

class Version1009Date20261112120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sheetmusic_libraries')) {
			$table = $schema->getTable('sheetmusic_libraries');
			if (!$table->hasColumn('naming_rule_preset')) {
				$table->addColumn('naming_rule_preset', Types::STRING, [
					'length' => 32,
					'notnull' => false,
					'default' => 'auto',
				]);
			}
		}

		return $schema;
	}
}
