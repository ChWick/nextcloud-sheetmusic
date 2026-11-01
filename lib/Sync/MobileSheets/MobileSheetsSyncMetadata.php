<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use PDO;

/**
 * Encapsulates MobileSheets sync metadata loaded from SQLite _nc_sync_metadata table.
 */
final class MobileSheetsSyncMetadata {
	/**
	 * @param bool $hasMetadata Whether the SQLite database contained the _nc_sync_metadata table.
	 * @param array<string, array<int, array{uuid: string, client_id: ?int, updated_at: int, extra: array<string, mixed>}>> $byTypeAndId
	 * @param array<string, array<string, array{uuid: string, client_id: ?int, updated_at: int, extra: array<string, mixed>}>> $byTypeAndUuid
	 */
	public function __construct(
		public readonly bool $hasMetadata = false,
		public readonly array $byTypeAndId = [],
		public readonly array $byTypeAndUuid = [],
	) {
	}

	/**
	 * Load sync metadata from an SQLite database connection.
	 */
	public static function fromDatabase(PDO $db): self {
		if (!MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata')) {
			return new self(hasMetadata: false);
		}

		$stmt = $db->query('SELECT entity_type, uuid, client_id, updated_at, extra_json FROM _nc_sync_metadata');
		if ($stmt === false) {
			return new self(hasMetadata: true);
		}

		$byTypeAndId = [];
		$byTypeAndUuid = [];

		while ($row = $stmt->fetch()) {
			$type = (string)$row['entity_type'];
			$uuid = (string)$row['uuid'];
			$clientId = isset($row['client_id']) && $row['client_id'] !== null ? (int)$row['client_id'] : null;
			$updatedAt = (int)($row['updated_at'] ?? 0);

			$extra = [];
			if (!empty($row['extra_json'])) {
				try {
					/** @var array<string, mixed> $extra */
					$extra = json_decode((string)$row['extra_json'], true, 512, JSON_THROW_ON_ERROR);
				} catch (\Throwable) {
					$extra = [];
				}
			}

			$entry = [
				'uuid' => $uuid,
				'client_id' => $clientId,
				'updated_at' => $updatedAt,
				'extra' => $extra,
			];

			if ($clientId !== null) {
				$byTypeAndId[$type][$clientId] = $entry;
			}
			$byTypeAndUuid[$type][$uuid] = $entry;
		}

		return new self(
			hasMetadata: true,
			byTypeAndId: $byTypeAndId,
			byTypeAndUuid: $byTypeAndUuid,
		);
	}

	/**
	 * Find song metadata by client ID.
	 *
	 * @return array{uuid: string, client_id: ?int, updated_at: int, extra: array<string, mixed>}|null
	 */
	public function getSongMeta(int $clientId): ?array {
		return $this->byTypeAndId['song'][$clientId] ?? null;
	}

	public function isSongMovement(int $clientId): bool {
		return isset($this->byTypeAndId['song_movement'][$clientId]);
	}

	public function isMovementCollection(int $clientId): bool {
		return isset($this->byTypeAndId['movement_collection'][$clientId]);
	}

	/**
	 * @return array{uuid: string, client_id: ?int, updated_at: int, extra: array<string, mixed>}|null
	 */
	public function getMovementMeta(int $clientId): ?array {
		return $this->byTypeAndId['song_movement'][$clientId] ?? null;
	}

	/**
	 * Find file metadata by client ID.
	 *
	 * @return array{uuid: string, client_id: ?int, updated_at: int, extra: array<string, mixed>}|null
	 */
	public function getFileMeta(int $clientId): ?array {
		return $this->byTypeAndId['file'][$clientId] ?? null;
	}

	/**
	 * Find entity metadata by entity type and client ID.
	 *
	 * @return array{uuid: string, client_id: ?int, updated_at: int, extra: array<string, mixed>}|null
	 */
	public function getEntityMeta(string $type, int $clientId): ?array {
		return $this->byTypeAndId[$type][$clientId]
			?? ($this->byTypeAndId['entity:' . $type][$clientId] ?? null);
	}

	/**
	 * Return all metadata entries for a given type, keyed by client ID.
	 *
	 * @return array<int, array{uuid: string, client_id: ?int, updated_at: int, extra: array<string, mixed>}>
	 */
	public function getEntriesByType(string $type): array {
		return $this->byTypeAndId[$type] ?? [];
	}

	/**
	 * Convert to array format for backward compatibility.
	 *
	 * @return array{byTypeAndId: array, byTypeAndUuid: array, hasMetadata: bool}
	 */
	public function toArray(): array {
		return [
			'byTypeAndId' => $this->byTypeAndId,
			'byTypeAndUuid' => $this->byTypeAndUuid,
			'hasMetadata' => $this->hasMetadata,
		];
	}
}
