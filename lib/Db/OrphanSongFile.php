<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class OrphanSongFile implements \JsonSerializable {
	public function __construct(
		public int $id,
		public int $songId,
		public int $fileId,
		public string $uuid,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			id: (int)($data['id'] ?? 0),
			songId: (int)($data['songId'] ?? 0),
			fileId: (int)($data['fileId'] ?? 0),
			uuid: (string)($data['uuid'] ?? ''),
		);
	}

	/**
	 * @return array{id: int, songId: int, fileId: int, uuid: string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'songId' => $this->songId,
			'fileId' => $this->fileId,
			'uuid' => $this->uuid,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
