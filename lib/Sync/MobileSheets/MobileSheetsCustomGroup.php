<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use JsonSerializable;

class MobileSheetsCustomGroup implements JsonSerializable {
	public function __construct(
		public int $id,
		public string $name,
	) {
	}

	/**
	 * @return array{id: int, name: string}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
		];
	}
}
