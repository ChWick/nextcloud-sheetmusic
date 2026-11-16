<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use JsonSerializable;

class InternalSetlistFile implements JsonSerializable {
	public function __construct(
		public ?int $id,
		public int $setlistId,
		public int $libraryId,
		public int $ocFileId = 0,
		public string $path = '',
		public string $filename = '',
		public string $mimeType = '',
		public int $fileSize = 0,
		public int $sortOrder = 0,
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
			setlistId: (int)$row['setlist_id'],
			libraryId: (int)$row['library_id'],
			ocFileId: isset($row['oc_file_id']) ? (int)$row['oc_file_id'] : 0,
			path: isset($row['path']) ? (string)$row['path'] : '',
			filename: isset($row['filename']) ? (string)$row['filename'] : '',
			mimeType: isset($row['mime_type']) ? (string)$row['mime_type'] : '',
			fileSize: isset($row['file_size']) ? (int)$row['file_size'] : 0,
			sortOrder: isset($row['sort_order']) ? (int)$row['sort_order'] : 0,
			createdAt: isset($row['created_at']) ? (int)$row['created_at'] : 0,
			updatedAt: isset($row['updated_at']) ? (int)$row['updated_at'] : 0,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toRow(): array {
		return [
			'id' => $this->id,
			'setlist_id' => $this->setlistId,
			'library_id' => $this->libraryId,
			'oc_file_id' => $this->ocFileId,
			'path' => $this->path,
			'filename' => $this->filename,
			'mime_type' => $this->mimeType,
			'file_size' => $this->fileSize,
			'sort_order' => $this->sortOrder,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}

	/**
	 * @return array{
	 *     id: ?int,
	 *     setlistId: int,
	 *     libraryId: int,
	 *     ocFileId: int,
	 *     path: string,
	 *     filename: string,
	 *     mimeType: string,
	 *     fileSize: int,
	 *     sortOrder: int,
	 *     createdAt: int,
	 *     updatedAt: int
	 * }
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'setlistId' => $this->setlistId,
			'libraryId' => $this->libraryId,
			'ocFileId' => $this->ocFileId,
			'path' => $this->path,
			'filename' => $this->filename,
			'mimeType' => $this->mimeType,
			'fileSize' => $this->fileSize,
			'sortOrder' => $this->sortOrder,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
