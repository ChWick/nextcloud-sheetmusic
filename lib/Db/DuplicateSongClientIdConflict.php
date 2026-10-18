<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class DuplicateSongClientIdConflict implements \JsonSerializable, \ArrayAccess {
	/**
	 * @param list<int> $songIds
	 * @param list<string> $titles
	 */
	public function __construct(
		public int $clientId,
		public array $songIds,
		public array $titles,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			clientId: (int)($data['clientId'] ?? 0),
			songIds: array_values(array_map('intval', (array)($data['songIds'] ?? []))),
			titles: array_values(array_map('strval', (array)($data['titles'] ?? []))),
		);
	}

	/**
	 * @return array{clientId: int, songIds: list<int>, titles: list<string>}
	 */
	public function toArray(): array {
		return [
			'clientId' => $this->clientId,
			'songIds' => $this->songIds,
			'titles' => $this->titles,
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
