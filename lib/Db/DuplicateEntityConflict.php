<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class DuplicateEntityConflict implements \JsonSerializable, \ArrayAccess {
	/**
	 * @param list<int> $duplicateIds
	 */
	public function __construct(
		public string $entityType,
		public string $name,
		public int $keepId,
		public array $duplicateIds,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			entityType: (string)($data['entityType'] ?? ''),
			name: (string)($data['name'] ?? ''),
			keepId: (int)($data['keepId'] ?? 0),
			duplicateIds: array_values(array_map('intval', (array)($data['duplicateIds'] ?? []))),
		);
	}

	/**
	 * @return array{entityType: string, name: string, keepId: int, duplicateIds: list<int>}
	 */
	public function toArray(): array {
		return [
			'entityType' => $this->entityType,
			'name' => $this->name,
			'keepId' => $this->keepId,
			'duplicateIds' => $this->duplicateIds,
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
