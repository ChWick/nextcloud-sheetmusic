<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class InternalSong {
	public function __construct(
		public ?int $id = null,
		public string $uuid = '',
		public int $libraryId = 0,
		public string $title = '',
		public ?string $sortTitle = null,
		public int $rating = 0,
		public int $difficulty = 0,
		public int $duration = 0,
		public ?string $keywords = null,
		public ?string $notes = null,
		public ?string $opus = null,
		public ?string $catalogNumber = null,
		public ?string $songNumber = null,
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
			title: (string)$row['title'],
			sortTitle: isset($row['sort_title']) && $row['sort_title'] !== null ? (string)$row['sort_title'] : null,
			rating: isset($row['rating']) ? (int)$row['rating'] : 0,
			difficulty: isset($row['difficulty']) ? (int)$row['difficulty'] : 0,
			duration: isset($row['duration']) ? (int)$row['duration'] : 0,
			keywords: isset($row['keywords']) && $row['keywords'] !== null ? (string)$row['keywords'] : null,
			notes: isset($row['notes']) && $row['notes'] !== null ? (string)$row['notes'] : null,
			opus: isset($row['opus']) && $row['opus'] !== null ? (string)$row['opus'] : null,
			catalogNumber: isset($row['catalog_number']) && $row['catalog_number'] !== null ? (string)$row['catalog_number'] : null,
			songNumber: isset($row['song_number']) && $row['song_number'] !== null ? (string)$row['song_number'] : null,
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
			'title' => $this->title,
			'sort_title' => $this->sortTitle,
			'rating' => $this->rating,
			'difficulty' => $this->difficulty,
			'duration' => $this->duration,
			'keywords' => $this->keywords,
			'notes' => $this->notes,
			'opus' => $this->opus,
			'catalog_number' => $this->catalogNumber,
			'song_number' => $this->songNumber,
			'client_id' => $this->clientId,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
		];
	}
}
