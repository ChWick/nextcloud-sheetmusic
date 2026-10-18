<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class LibraryData implements \JsonSerializable, \ArrayAccess {
	/**
	 * @param list<MobileSheetsSong> $songs
	 * @param list<MobileSheetsComposer> $composers
	 * @param list<MobileSheetsBook> $books
	 * @param list<MobileSheetsCollection> $collections
	 * @param list<MobileSheetsArtist> $artists
	 * @param list<MobileSheetsGenre> $genres
	 * @param list<MobileSheetsKey> $keys
	 * @param list<MobileSheetsCustomGroup> $customGroups
	 * @param list<MobileSheetsYear> $years
	 * @param list<MobileSheetsSignature> $signatures
	 * @param list<MobileSheetsSourceType> $sourceTypes
	 * @param list<MobileSheetsEntity> $instruments
	 */
	public function __construct(
		public array $songs = [],
		public array $composers = [],
		public array $books = [],
		public array $collections = [],
		public array $artists = [],
		public array $genres = [],
		public array $keys = [],
		public array $customGroups = [],
		public array $years = [],
		public array $signatures = [],
		public array $sourceTypes = [],
		public array $instruments = [],
		public ?int $createdSongId = null,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			songs: $data['songs'] ?? [],
			composers: $data['composers'] ?? [],
			books: $data['books'] ?? [],
			collections: $data['collections'] ?? [],
			artists: $data['artists'] ?? [],
			genres: $data['genres'] ?? [],
			keys: $data['keys'] ?? [],
			customGroups: $data['customGroups'] ?? [],
			years: $data['years'] ?? [],
			signatures: $data['signatures'] ?? [],
			sourceTypes: $data['sourceTypes'] ?? [],
			instruments: $data['instruments'] ?? [],
			createdSongId: isset($data['createdSongId']) ? (int)$data['createdSongId'] : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data = [
			'songs' => $this->songs,
			'composers' => $this->composers,
			'books' => $this->books,
			'collections' => $this->collections,
			'artists' => $this->artists,
			'genres' => $this->genres,
			'keys' => $this->keys,
			'customGroups' => $this->customGroups,
			'years' => $this->years,
			'signatures' => $this->signatures,
			'sourceTypes' => $this->sourceTypes,
			'instruments' => $this->instruments,
		];
		if ($this->createdSongId !== null) {
			$data['createdSongId'] = $this->createdSongId;
		}
		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	public function offsetExists(mixed $offset): bool {
		return match ($offset) {
			'songs', 'composers', 'books', 'collections', 'artists',
			'genres', 'keys', 'customGroups', 'years', 'signatures',
			'sourceTypes', 'instruments' => true,
			'createdSongId' => $this->createdSongId !== null,
			default => false,
		};
	}

	public function offsetGet(mixed $offset): mixed {
		return match ($offset) {
			'songs' => $this->songs,
			'composers' => $this->composers,
			'books' => $this->books,
			'collections' => $this->collections,
			'artists' => $this->artists,
			'genres' => $this->genres,
			'keys' => $this->keys,
			'customGroups' => $this->customGroups,
			'years' => $this->years,
			'signatures' => $this->signatures,
			'sourceTypes' => $this->sourceTypes,
			'instruments' => $this->instruments,
			'createdSongId' => $this->createdSongId,
			default => null,
		};
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		match ($offset) {
			'songs' => $this->songs = (array)$value,
			'composers' => $this->composers = (array)$value,
			'books' => $this->books = (array)$value,
			'collections' => $this->collections = (array)$value,
			'artists' => $this->artists = (array)$value,
			'genres' => $this->genres = (array)$value,
			'keys' => $this->keys = (array)$value,
			'customGroups' => $this->customGroups = (array)$value,
			'years' => $this->years = (array)$value,
			'signatures' => $this->signatures = (array)$value,
			'sourceTypes' => $this->sourceTypes = (array)$value,
			'instruments' => $this->instruments = (array)$value,
			'createdSongId' => $this->createdSongId = $value !== null ? (int)$value : null,
			default => null,
		};
	}

	public function offsetUnset(mixed $offset): void {
		if ($offset === 'createdSongId') {
			$this->createdSongId = null;
		}
	}
}

