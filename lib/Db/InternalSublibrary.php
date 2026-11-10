<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use JsonSerializable;

class InternalSublibrary implements JsonSerializable {
	/**
	 * @param array<string, list<string>|list<int>|string> $filters
	 */
	public function __construct(
		public ?int $id = null,
		public int $libraryId = 0,
		public string $name = '',
		public string $slug = '',
		public array $filters = [],
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public ?int $folderId = null,
		public bool $isShared = false,
		public string $ownerId = '',
		public string $ownerDisplayName = '',
		public string $permission = 'admin',
		public bool $canWrite = true,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		$filters = [];
		if (isset($row['filters_json']) && is_string($row['filters_json']) && $row['filters_json'] !== '') {
			try {
				$decoded = json_decode($row['filters_json'], true, 512, JSON_THROW_ON_ERROR);
				if (is_array($decoded)) {
					$filters = $decoded;
				}
			} catch (\Throwable) {
				$filters = [];
			}
		} elseif (isset($row['filters']) && is_array($row['filters'])) {
			$filters = $row['filters'];
		}

		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			libraryId: (int)($row['library_id'] ?? $row['libraryId'] ?? 0),
			name: (string)($row['name'] ?? ''),
			slug: (string)($row['slug'] ?? ''),
			filters: $filters,
			createdAt: (int)($row['created_at'] ?? $row['createdAt'] ?? 0),
			updatedAt: (int)($row['updated_at'] ?? $row['updatedAt'] ?? 0),
			folderId: isset($row['folder_id']) && $row['folder_id'] !== null ? (int)$row['folder_id'] : (isset($row['folderId']) && $row['folderId'] !== null ? (int)$row['folderId'] : null),
			isShared: isset($row['is_shared']) ? (bool)$row['is_shared'] : (isset($row['isShared']) ? (bool)$row['isShared'] : false),
			ownerId: (string)($row['owner_id'] ?? $row['ownerId'] ?? ''),
			ownerDisplayName: (string)($row['owner_display_name'] ?? $row['ownerDisplayName'] ?? ''),
			permission: (string)($row['permission'] ?? 'admin'),
			canWrite: isset($row['can_write']) ? (bool)$row['can_write'] : (isset($row['canWrite']) ? (bool)$row['canWrite'] : true),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'libraryId' => $this->libraryId,
			'name' => $this->name,
			'slug' => $this->slug,
			'filters' => $this->filters,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'folderId' => $this->folderId,
			'isShared' => $this->isShared,
			'ownerId' => $this->ownerId,
			'ownerDisplayName' => $this->ownerDisplayName,
			'permission' => $this->permission,
			'canWrite' => $this->canWrite,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'libraryId' => $this->libraryId,
			'name' => $this->name,
			'slug' => $this->slug,
			'filters' => $this->filters,
			'folderId' => $this->folderId,
			'isShared' => $this->isShared,
			'ownerId' => $this->ownerId,
			'ownerDisplayName' => $this->ownerDisplayName,
			'permission' => $this->permission,
			'canWrite' => $this->canWrite,
		];
	}
}
