<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;

class InternalSongFile {
	public const FILE_TYPE_OTHER = 0;
	public const FILE_TYPE_PDF = 1;
	public const FILE_TYPE_IMAGE = 2;
	public const FILE_TYPE_TEXT = 3;
	public const FILE_TYPE_ABC = 4;

	public static function determineFileType(string $path): int {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return match ($extension) {
			'pdf' => self::FILE_TYPE_PDF,
			'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg' => self::FILE_TYPE_IMAGE,
			'txt', 'text', 'cho', 'chopro', 'chordpro', 'crd', 'pro' => self::FILE_TYPE_TEXT,
			'abc' => self::FILE_TYPE_ABC,
			default => self::FILE_TYPE_OTHER,
		};
	}

	public function __construct(
		public ?int $id = null,
		public string $uuid = '',
		public int $songId = 0,
		public int $fileId = 0,
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
	 * Normalizes a page order string.
	 * MobileSheets always represents single-page files as '1-1', never '1'.
	 * An empty or single '1' page order is normalized to '1-N' (or '1-1').
	 */
	public static function normalizePageOrder(?string $pageOrder, int $pageCount = 1): ?string {
		if ($pageOrder === null) {
			return null;
		}
		$trimmed = trim($pageOrder);
		if ($trimmed === '' || $trimmed === '1') {
			return MobileSheetsPdf::pageOrder($pageCount);
		}
		return $trimmed;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		$pageCount = isset($row['page_count']) ? (int)$row['page_count'] : 0;
		$rawPageOrder = isset($row['page_order']) && $row['page_order'] !== null ? (string)$row['page_order'] : null;
		$pageOrder = self::normalizePageOrder($rawPageOrder, $pageCount);

		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			uuid: (string)$row['uuid'],
			songId: (int)$row['song_id'],
			fileId: (int)$row['file_id'],
			pageOrder: $pageOrder,
			pageCount: $pageCount,
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
