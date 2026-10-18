<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class DuplicateSongEntityAssignment implements \JsonSerializable, \ArrayAccess {
	public function __construct(
		public int $songId,
		public string $entityType,
		public int $entityId,
		public int $count,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			songId: (int)($data['songId'] ?? 0),
			entityType: (string)($data['entityType'] ?? ''),
			entityId: (int)($data['entityId'] ?? 0),
			count: (int)($data['count'] ?? 0),
		);
	}

	/**
	 * @return array{songId: int, entityType: string, entityId: int, count: int}
	 */
	public function toArray(): array {
		return [
			'songId' => $this->songId,
			'entityType' => $this->entityType,
			'entityId' => $this->entityId,
			'count' => $this->count,
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
