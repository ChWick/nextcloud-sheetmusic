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
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSourceType;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsYear;

class LibraryData implements \JsonSerializable {
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
	 * @param list<MobileSheetsEntity> $arrangers
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
		public array $arrangers = [],
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
			arrangers: $data['arrangers'] ?? [],
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
			'arrangers' => $this->arrangers,
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
}
