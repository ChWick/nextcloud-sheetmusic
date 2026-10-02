<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use JsonSerializable;

class MobileSheetsSongNote implements JsonSerializable {
	public function __construct(
		public readonly int $id,
		public readonly int $songId,
		public readonly string $note,
		public readonly string $title = '',
		public readonly ?int $dateCreated = null,
		public readonly ?int $lastModified = null,
	) {
	}

	/**
	 * @return array{id: int, songId: int, note: string, title: string, dateCreated: ?int, lastModified: ?int}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'songId' => $this->songId,
			'note' => $this->note,
			'title' => $this->title,
			'dateCreated' => $this->dateCreated,
			'lastModified' => $this->lastModified,
		];
	}
}
