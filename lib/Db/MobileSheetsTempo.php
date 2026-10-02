<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use JsonSerializable;

class MobileSheetsTempo implements JsonSerializable {
	public function __construct(
		public readonly int $id,
		public readonly int $songId,
		public readonly int $tempo,
	) {
	}

	/**
	 * @return array{id: int, songId: int, tempo: int}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'songId' => $this->songId,
			'tempo' => $this->tempo,
		];
	}
}
