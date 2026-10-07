<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20261005120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$tableName = 'mobilesheets_libraries';

		if (!$schema->hasTable($tableName)) {
			return null;
		}

		$table = $schema->getTable($tableName);
		if (!$table->hasColumn('auto_paths')) {
			$table->addColumn('auto_paths', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
		}

		if (!$table->hasColumn('auto_pdf_metadata')) {
			$table->addColumn('auto_pdf_metadata', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
		}

		return $schema;
	}
}

