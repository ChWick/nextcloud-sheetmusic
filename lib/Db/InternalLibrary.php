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
		public ?int $folderId = null,
		public bool $isShared = false,
		public string $ownerId = '',
		public string $ownerDisplayName = '',
		public string $permission = 'admin',
		public bool $canWrite = true,
		public ?int $sublibraryId = null,
		public ?string $sublibraryName = null,
		public string $namingRulePreset = 'auto',
		public bool $mobilesheetsSyncEnabled = false,
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
			folderId: isset($row['folder_id']) && $row['folder_id'] !== null ? (int)$row['folder_id'] : (isset($row['folderId']) && $row['folderId'] !== null ? (int)$row['folderId'] : null),
			isShared: isset($row['is_shared']) ? (bool)$row['is_shared'] : (isset($row['isShared']) ? (bool)$row['isShared'] : false),
			ownerId: (string)($row['owner_id'] ?? $row['ownerId'] ?? ''),
			ownerDisplayName: (string)($row['owner_display_name'] ?? $row['ownerDisplayName'] ?? ''),
			permission: (string)($row['permission'] ?? 'admin'),
			canWrite: isset($row['can_write']) ? (bool)$row['can_write'] : (isset($row['canWrite']) ? (bool)$row['canWrite'] : true),
			sublibraryId: isset($row['sublibrary_id']) && $row['sublibrary_id'] !== null ? (int)$row['sublibrary_id'] : (isset($row['sublibraryId']) && $row['sublibraryId'] !== null ? (int)$row['sublibraryId'] : null),
			sublibraryName: isset($row['sublibrary_name']) ? (string)$row['sublibrary_name'] : (isset($row['sublibraryName']) ? (string)$row['sublibraryName'] : null),
			namingRulePreset: (string)($row['naming_rule_preset'] ?? $row['namingRulePreset'] ?? 'auto'),
			mobilesheetsSyncEnabled: isset($row['mobilesheets_sync_enabled']) ? (bool)$row['mobilesheets_sync_enabled'] : (isset($row['mobilesheetsSyncEnabled']) ? (bool)$row['mobilesheetsSyncEnabled'] : false),
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
			'folderId' => $this->folderId,
			'autoPaths' => $this->autoPaths,
			'autoPdfMetadata' => $this->autoPdfMetadata,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'isShared' => $this->isShared,
			'ownerId' => $this->ownerId ?: $this->userId,
			'ownerDisplayName' => $this->ownerDisplayName,
			'permission' => $this->permission,
			'canWrite' => $this->canWrite,
			'sublibraryId' => $this->sublibraryId,
			'sublibraryName' => $this->sublibraryName,
			'namingRulePreset' => $this->namingRulePreset,
			'mobilesheetsSyncEnabled' => $this->mobilesheetsSyncEnabled,
		];
	}

	/**
	 * @return array{id: int|null, path: string, folderId: int|null, autoPaths: bool, autoPdfMetadata: bool, isShared: bool, ownerId: string, ownerDisplayName: string, permission: string, canWrite: bool, sublibraryId?: int|null, sublibraryName?: string|null, namingRulePreset: string, mobilesheetsSyncEnabled: bool}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'path' => $this->path,
			'folderId' => $this->folderId,
			'autoPaths' => $this->autoPaths,
			'autoPdfMetadata' => $this->autoPdfMetadata,
			'isShared' => $this->isShared,
			'ownerId' => $this->ownerId ?: $this->userId,
			'ownerDisplayName' => $this->ownerDisplayName,
			'permission' => $this->permission,
			'canWrite' => $this->canWrite,
			'sublibraryId' => $this->sublibraryId,
			'sublibraryName' => $this->sublibraryName,
			'namingRulePreset' => $this->namingRulePreset,
			'mobilesheetsSyncEnabled' => $this->mobilesheetsSyncEnabled,
		];
	}
}
