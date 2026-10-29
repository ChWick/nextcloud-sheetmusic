<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use JsonSerializable;

class MobileSheetsAudioFile implements JsonSerializable {
	public function __construct(
		public readonly int $id,
		public readonly int $songId,
		public readonly string $title,
		public readonly string $file,
	) {
	}

	/**
	 * @return array{id: int, songId: int, title: string, file: string}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'songId' => $this->songId,
			'title' => $this->title,
			'file' => $this->file,
		];
	}
}
