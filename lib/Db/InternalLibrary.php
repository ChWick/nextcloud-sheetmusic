<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class InternalLibrary implements \JsonSerializable {
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
			userId: (string)($row['user_id'] ?? $row['userId'] ?? ''),
			path: (string)($row['path'] ?? ''),
			autoPaths: isset($row['auto_paths']) ? (bool)$row['auto_paths'] : (isset($row['autoPaths']) ? (bool)$row['autoPaths'] : true),
			autoPdfMetadata: isset($row['auto_pdf_metadata']) ? (bool)$row['auto_pdf_metadata'] : (isset($row['autoPdfMetadata']) ? (bool)$row['autoPdfMetadata'] : true),
			createdAt: (int)($row['created_at'] ?? $row['createdAt'] ?? 0),
			updatedAt: (int)($row['updated_at'] ?? $row['updatedAt'] ?? 0),
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

	/**
	 * @return array{id: int|null, path: string, autoPaths: bool, autoPdfMetadata: bool}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'path' => $this->path,
			'autoPaths' => $this->autoPaths,
			'autoPdfMetadata' => $this->autoPdfMetadata,
		];
	}
}
