<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class InternalSongHistory {
	public const ACTION_CREATED = 'created';
	public const ACTION_UPDATED = 'updated';
	public const ACTION_SYNCED = 'synced';
	public const ACTION_DELETED = 'deleted';
	public const ACTION_REVERTED = 'reverted';
	public const ACTION_RESTORED = 'restored';

	public function __construct(
		public ?int $id = null,
		public int $libraryId = 0,
		public int $songId = 0,
		public string $songUuid = '',
		public ?string $userId = null,
		public string $action = self::ACTION_UPDATED,
		public ?string $summary = null,
		public array|string $snapshot = [],
		public int $createdAt = 0,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		$snapshotRaw = $row['snapshot'] ?? '[]';
		$snapshot = is_string($snapshotRaw)
			? (json_decode($snapshotRaw, true) ?: [])
			: (array)$snapshotRaw;

		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			libraryId: (int)($row['library_id'] ?? 0),
			songId: (int)($row['song_id'] ?? 0),
			songUuid: (string)($row['song_uuid'] ?? ''),
			userId: isset($row['user_id']) && $row['user_id'] !== null ? (string)$row['user_id'] : null,
			action: (string)($row['action'] ?? self::ACTION_UPDATED),
			summary: isset($row['summary']) && $row['summary'] !== null ? (string)$row['summary'] : null,
			snapshot: $snapshot,
			createdAt: (int)($row['created_at'] ?? 0),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getSnapshotArray(): array {
		if (is_array($this->snapshot)) {
			return $this->snapshot;
		}
		return json_decode($this->snapshot, true) ?: [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'libraryId' => $this->libraryId,
			'songId' => $this->songId,
			'songUuid' => $this->songUuid,
			'userId' => $this->userId,
			'action' => $this->action,
			'summary' => $this->summary,
			'snapshot' => $this->getSnapshotArray(),
			'createdAt' => $this->createdAt,
		];
	}
}
