<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use JsonSerializable;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBookmark;

class InternalSongBookmark implements JsonSerializable {
	public function __construct(
		public ?int $id,
		public int $songId,
		public string $name,
		public int $pageNum,
		public ?int $endPage = null,
		public int $position = 0,
		public int $showInLibrary = 1,
		public int $createdAt = 0,
		public int $updatedAt = 0,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			songId: (int)$row['song_id'],
			name: (string)$row['name'],
			pageNum: (int)$row['page_num'],
			endPage: isset($row['end_page']) && $row['end_page'] !== null ? (int)$row['end_page'] : null,
			position: isset($row['position']) ? (int)$row['position'] : 0,
			showInLibrary: isset($row['show_in_library']) ? (int)$row['show_in_library'] : 1,
			createdAt: isset($row['created_at']) ? (int)$row['created_at'] : 0,
			updatedAt: isset($row['updated_at']) ? (int)$row['updated_at'] : 0,
		);
	}

	public function toMobileSheetsBookmark(): MobileSheetsBookmark {
		return new MobileSheetsBookmark(
			id: $this->id ?? 0,
			songId: $this->songId,
			name: $this->name,
			pageNum: $this->pageNum,
			showInLibrary: $this->showInLibrary,
			endPage: $this->endPage,
		);
	}

	/**
	 * @return array{id: ?int, songId: int, name: string, pageNum: int, endPage: ?int, position: int, showInLibrary: int}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'songId' => $this->songId,
			'name' => $this->name,
			'pageNum' => $this->pageNum,
			'endPage' => $this->endPage,
			'position' => $this->position,
			'showInLibrary' => $this->showInLibrary,
		];
	}
}
