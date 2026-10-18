<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class SongEntityLink {
	public function __construct(
		public int $linkId,
		public int $songId,
		public int $entityId,
		public string $type,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			linkId: (int)($row['link_id'] ?? $row['linkId'] ?? $row['id'] ?? 0),
			songId: (int)($row['song_id'] ?? $row['songId'] ?? 0),
			entityId: (int)($row['entity_id'] ?? $row['entityId'] ?? 0),
			type: (string)($row['type'] ?? ''),
		);
	}

	/**
	 * @return array{link_id: int, song_id: int, entity_id: int, type: string}
	 */
	public function toArray(): array {
		return [
			'link_id' => $this->linkId,
			'song_id' => $this->songId,
			'entity_id' => $this->entityId,
			'type' => $this->type,
		];
	}
}
