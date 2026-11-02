<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use JsonSerializable;

class InternalSetlistItem implements JsonSerializable {
	public function __construct(
		public ?int $id,
		public int $setlistId,
		public int $songId,
		public ?int $bookmarkId = null,
		public int $position = 0,
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public ?string $songTitle = null,
		public ?string $bookmarkName = null,
		public ?int $pageNum = null,
		public ?int $endPage = null,
		public ?int $duration = null,
		public ?string $artist = null,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			setlistId: (int)$row['setlist_id'],
			songId: (int)$row['song_id'],
			bookmarkId: isset($row['bookmark_id']) && $row['bookmark_id'] !== null ? (int)$row['bookmark_id'] : null,
			position: isset($row['position']) ? (int)$row['position'] : 0,
			createdAt: isset($row['created_at']) ? (int)$row['created_at'] : 0,
			updatedAt: isset($row['updated_at']) ? (int)$row['updated_at'] : 0,
			songTitle: isset($row['song_title']) ? (string)$row['song_title'] : null,
			bookmarkName: isset($row['bookmark_name']) ? (string)$row['bookmark_name'] : null,
			pageNum: isset($row['page_num']) && $row['page_num'] !== null ? (int)$row['page_num'] : null,
			endPage: isset($row['end_page']) && $row['end_page'] !== null ? (int)$row['end_page'] : null,
			duration: isset($row['duration']) && $row['duration'] !== null ? (int)$row['duration'] : null,
			artist: isset($row['artist']) ? (string)$row['artist'] : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toRow(): array {
		return [
			'id' => $this->id,
			'setlist_id' => $this->setlistId,
			'song_id' => $this->songId,
			'bookmark_id' => $this->bookmarkId,
			'position' => $this->position,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}

	/**
	 * @return array{
	 *     id: ?int,
	 *     setlistId: int,
	 *     songId: int,
	 *     bookmarkId: ?int,
	 *     position: int,
	 *     songTitle: ?string,
	 *     bookmarkName: ?string,
	 *     pageNum: ?int,
	 *     endPage: ?int,
	 *     duration: ?int,
	 *     artist: ?string
	 * }
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'setlistId' => $this->setlistId,
			'songId' => $this->songId,
			'bookmarkId' => $this->bookmarkId,
			'position' => $this->position,
			'songTitle' => $this->songTitle,
			'bookmarkName' => $this->bookmarkName,
			'pageNum' => $this->pageNum,
			'endPage' => $this->endPage,
			'duration' => $this->duration,
			'artist' => $this->artist,
		];
	}
}
