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
	 * Normalize a library path to its clean relative folder path (no leading/trailing slashes;
	 * if pointing to a .db file, returns its containing directory or '' for root).
	 */
	public static function normalizeFolderPath(string $path): string {
		$clean = trim($path, '/');
		if ($clean === '' || $clean === '.') {
			return '';
		}
		if (str_ends_with(strtolower($clean), '.db')) {
			$directory = dirname($clean);
			return ($directory === '' || $directory === '.') ? '' : $directory;
		}
		return $clean;
	}

	public function folderPath(): string {
		return self::normalizeFolderPath($this->path);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			userId: (string)($row['user_id'] ?? $row['userId'] ?? ''),
			path: (string)($row['path'] ?? ''),
			autoPaths: (bool)($row['auto_paths'] ?? $row['autoPaths'] ?? true),
			autoPdfMetadata: (bool)($row['auto_pdf_metadata'] ?? $row['autoPdfMetadata'] ?? true),
			createdAt: (int)($row['created_at'] ?? $row['createdAt'] ?? 0),
			updatedAt: (int)($row['updated_at'] ?? $row['updatedAt'] ?? 0),
			folderId: isset($row['folder_id']) ? (int)$row['folder_id'] : (isset($row['folderId']) ? (int)$row['folderId'] : null),
			isShared: (bool)($row['is_shared'] ?? $row['isShared'] ?? false),
			ownerId: (string)($row['owner_id'] ?? $row['ownerId'] ?? ''),
			ownerDisplayName: (string)($row['owner_display_name'] ?? $row['ownerDisplayName'] ?? ''),
			permission: (string)($row['permission'] ?? 'admin'),
			canWrite: (bool)($row['can_write'] ?? $row['canWrite'] ?? true),
			sublibraryId: isset($row['sublibrary_id']) ? (int)$row['sublibrary_id'] : (isset($row['sublibraryId']) ? (int)$row['sublibraryId'] : null),
			sublibraryName: isset($row['sublibrary_name']) ? (string)$row['sublibrary_name'] : (isset($row['sublibraryName']) ? (string)$row['sublibraryName'] : null),
			namingRulePreset: (string)($row['naming_rule_preset'] ?? $row['namingRulePreset'] ?? 'auto'),
			mobilesheetsSyncEnabled: (bool)($row['mobilesheets_sync_enabled'] ?? $row['mobilesheetsSyncEnabled'] ?? false),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			...$this->jsonSerialize(),
			'userId' => $this->userId,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
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
