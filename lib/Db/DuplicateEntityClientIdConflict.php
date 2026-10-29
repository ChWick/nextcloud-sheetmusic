<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class DuplicateEntityClientIdConflict implements \JsonSerializable {
	/**
	 * @param list<int> $entityIds
	 * @param list<string> $names
	 */
	public function __construct(
		public string $type,
		public int $clientId,
		public array $entityIds,
		public array $names,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			type: (string)($data['type'] ?? ''),
			clientId: (int)($data['clientId'] ?? 0),
			entityIds: array_values(array_map('intval', (array)($data['entityIds'] ?? []))),
			names: array_values(array_map('strval', (array)($data['names'] ?? []))),
		);
	}

	/**
	 * @return array{type: string, clientId: int, entityIds: list<int>, names: list<string>}
	 */
	public function toArray(): array {
		return [
			'type' => $this->type,
			'clientId' => $this->clientId,
			'entityIds' => $this->entityIds,
			'names' => $this->names,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
