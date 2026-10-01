<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use JsonSerializable;

class MobileSheetsYear {
	public readonly string $name;

	public function __construct(
		public readonly int $id,
		public readonly int $year,
	) {
		$this->name = (string)$this->year;
	}
}
