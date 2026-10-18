<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class InternalLibrary implements \JsonSerializable, \ArrayAccess {
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
			autoPaths: LibraryMapper::autoPathsOf($row),
			autoPdfMetadata: LibraryMapper::autoPdfMetadataOf($row),
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

	public function offsetExists(mixed $offset): bool {
		return in_array($offset, [
			'id', 'userId', 'user_id', 'path', 'autoPaths', 'auto_paths',
			'autoPdfMetadata', 'auto_pdf_metadata', 'createdAt', 'created_at',
			'updatedAt', 'updated_at',
		], true);
	}

	public function offsetGet(mixed $offset): mixed {
		return match ($offset) {
			'id' => $this->id,
			'userId', 'user_id' => $this->userId,
			'path' => $this->path,
			'autoPaths', 'auto_paths' => $this->autoPaths,
			'autoPdfMetadata', 'auto_pdf_metadata' => $this->autoPdfMetadata,
			'createdAt', 'created_at' => $this->createdAt,
			'updatedAt', 'updated_at' => $this->updatedAt,
			default => null,
		};
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		match ($offset) {
			'id' => $this->id = $value !== null ? (int)$value : null,
			'userId', 'user_id' => $this->userId = (string)$value,
			'path' => $this->path = (string)$value,
			'autoPaths', 'auto_paths' => $this->autoPaths = (bool)$value,
			'autoPdfMetadata', 'auto_pdf_metadata' => $this->autoPdfMetadata = (bool)$value,
			'createdAt', 'created_at' => $this->createdAt = (int)$value,
			'updatedAt', 'updated_at' => $this->updatedAt = (int)$value,
			default => null,
		};
	}

	public function offsetUnset(mixed $offset): void {
	}
}
