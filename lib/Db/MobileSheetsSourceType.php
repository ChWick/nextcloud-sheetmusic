<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class MobileSheetsSourceType {
	public readonly string $name;

	public function __construct(
		public readonly int $id,
		public readonly string $type,
		?string $name = null,
	) {
		$this->name = $name ?? $type;
	}
}
