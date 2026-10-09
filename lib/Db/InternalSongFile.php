<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class InternalSongFile {
	public function __construct(
		public ?int $id,
		public string $uuid,
		public int $songId,
		public int $fileId,
		public ?string $pageOrder = null,
		public int $pageCount = 0,
		public int $fileSize = 0,
		public ?int $fileHash = null,
		public int $type = 0,
		public ?int $clientId = null,
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public ?int $deletedAt = null,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			uuid: (string)$row['uuid'],
			songId: (int)$row['song_id'],
			fileId: (int)$row['file_id'],
			pageOrder: isset($row['page_order']) && $row['page_order'] !== null ? (string)$row['page_order'] : null,
			pageCount: isset($row['page_count']) ? (int)$row['page_count'] : 0,
			fileSize: isset($row['file_size']) ? (int)$row['file_size'] : 0,
			fileHash: isset($row['file_hash']) && $row['file_hash'] !== null ? (int)$row['file_hash'] : null,
			type: isset($row['type']) ? (int)$row['type'] : 0,
			clientId: isset($row['client_id']) && $row['client_id'] !== null ? (int)$row['client_id'] : null,
			createdAt: isset($row['created_at']) ? (int)$row['created_at'] : 0,
			updatedAt: isset($row['updated_at']) ? (int)$row['updated_at'] : 0,
			deletedAt: isset($row['deleted_at']) && $row['deleted_at'] !== null ? (int)$row['deleted_at'] : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toRow(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'song_id' => $this->songId,
			'file_id' => $this->fileId,
			'page_order' => $this->pageOrder,
			'page_count' => $this->pageCount,
			'file_size' => $this->fileSize,
			'file_hash' => $this->fileHash,
			'type' => $this->type,
			'client_id' => $this->clientId,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
		];
	}
}
