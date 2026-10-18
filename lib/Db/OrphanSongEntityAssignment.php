<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class OrphanSongEntityAssignment implements \JsonSerializable, \ArrayAccess {
	public function __construct(
		public int $id,
		public int $songId,
		public int $entityId,
		public string $reason,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			id: (int)($data['id'] ?? 0),
			songId: (int)($data['songId'] ?? 0),
			entityId: (int)($data['entityId'] ?? 0),
			reason: (string)($data['reason'] ?? ''),
		);
	}

	/**
	 * @return array{id: int, songId: int, entityId: int, reason: string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'songId' => $this->songId,
			'entityId' => $this->entityId,
			'reason' => $this->reason,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	public function offsetExists(mixed $offset): bool {
		return property_exists($this, (string)$offset) && isset($this->{(string)$offset});
	}

	public function offsetGet(mixed $offset): mixed {
		return $this->{(string)$offset} ?? null;
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		$key = (string)$offset;
		if (property_exists($this, $key)) {
			$this->{$key} = $value;
		}
	}

	public function offsetUnset(mixed $offset): void {
	}
}
