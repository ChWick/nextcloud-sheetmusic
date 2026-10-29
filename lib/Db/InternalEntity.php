<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsArtist;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBook;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCollection;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCustomGroup;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsGenre;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsKey;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSignature;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSourceType;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsYear;

class InternalEntity {
	public function __construct(
		public ?int $id = null,
		public string $uuid = '',
		public int $libraryId = 0,
		public string $type = '',
		public string $name = '',
		public int $sortBy = 1,
		public int $ascending = 1,
		public ?int $clientId = null,
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public ?int $deletedAt = null,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			uuid: (string)$row['uuid'],
			libraryId: (int)$row['library_id'],
			type: (string)$row['type'],
			name: (string)$row['name'],
			sortBy: isset($row['sort_by']) ? (int)$row['sort_by'] : 1,
			ascending: isset($row['ascending']) ? (int)$row['ascending'] : 1,
			clientId: isset($row['client_id']) && $row['client_id'] !== null ? (int)$row['client_id'] : null,
			createdAt: isset($row['created_at']) ? (int)$row['created_at'] : 0,
			updatedAt: isset($row['updated_at']) ? (int)$row['updated_at'] : 0,
			deletedAt: isset($row['deleted_at']) && $row['deleted_at'] !== null ? (int)$row['deleted_at'] : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toRow(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'library_id' => $this->libraryId,
			'type' => $this->type,
			'name' => $this->name,
			'sort_by' => $this->sortBy,
			'ascending' => $this->ascending,
			'client_id' => $this->clientId,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
		];
	}

	public static function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	public function toMobileSheetsEntity(?string $type = null): object {
		$normType = InternalEntityMapper::normalizeType($type ?? $this->type);
		$id = (int)$this->id;
		return match ($normType) {
			'composer' => new MobileSheetsComposer($id, $this->name),
			'book' => new MobileSheetsBook($id, $this->name),
			'collection' => new MobileSheetsCollection($id, $this->name),
			'artist' => new MobileSheetsArtist($id, $this->name),
			'genre' => new MobileSheetsGenre($id, $this->name),
			'key' => new MobileSheetsKey($id, $this->name),
			'customgroup' => new MobileSheetsCustomGroup($id, $this->name),
			'year' => new MobileSheetsYear($id, (int)$this->name),
			'signature' => new MobileSheetsSignature($id, $this->name),
			'sourcetype' => new MobileSheetsSourceType($id, $this->name),
			'instrument' => new MobileSheetsCustomGroup($id, $this->name),
			default => new MobileSheetsCustomGroup($id, $this->name),
		};
	}
}
