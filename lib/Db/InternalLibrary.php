<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class InternalLibrary {
	public function __construct(
		public ?int $id = null,
		public string $userId = '',
		public string $path = '',
		public bool $autoPaths = true,
		public bool $autoPdfMetadata = true,
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
			userId: (string)($row['user_id'] ?? ''),
			path: (string)($row['path'] ?? ''),
			autoPaths: LibraryMapper::autoPathsOf($row),
			autoPdfMetadata: LibraryMapper::autoPdfMetadataOf($row),
			createdAt: (int)($row['created_at'] ?? 0),
			updatedAt: (int)($row['updated_at'] ?? 0),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'path' => $this->path,
			'autoPaths' => $this->autoPaths,
			'autoPdfMetadata' => $this->autoPdfMetadata,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}

