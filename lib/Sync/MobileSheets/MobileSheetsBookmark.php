<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use JsonSerializable;

class MobileSheetsBookmark implements JsonSerializable {
	public function __construct(
		public readonly int $id,
		public readonly int $songId,
		public readonly string $name,
		public readonly int $pageNum,
		public readonly int $showInLibrary = 0,
	) {
	}

	/**
	 * @return array{id: int, songId: int, name: string, pageNum: int, showInLibrary: int}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'songId' => $this->songId,
			'name' => $this->name,
			'pageNum' => $this->pageNum,
			'showInLibrary' => $this->showInLibrary,
		];
	}
}
