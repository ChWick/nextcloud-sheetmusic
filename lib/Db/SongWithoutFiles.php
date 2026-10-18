<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class SongWithoutFiles implements \JsonSerializable, \ArrayAccess {
	public function __construct(
		public int $id,
		public string $title,
		public string $uuid,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			id: (int)($data['id'] ?? 0),
			title: (string)($data['title'] ?? ''),
			uuid: (string)($data['uuid'] ?? ''),
		);
	}

	/**
	 * @return array{id: int, title: string, uuid: string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
			'uuid' => $this->uuid,
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
