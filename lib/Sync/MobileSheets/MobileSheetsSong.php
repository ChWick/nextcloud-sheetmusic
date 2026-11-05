<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

class MobileSheetsSong {
	/**
	 * @param list<MobileSheetsFile> $files
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
	 * @param list<MobileSheetsBookmark> $bookmarks
	 * @param list<MobileSheetsAudioFile> $audioFiles
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly array $files = [],
		public readonly array $composers = [],
		public readonly array $books = [],
		public readonly array $collections = [],
		public readonly array $artists = [],
		public readonly array $genres = [],
		public readonly array $keys = [],
		public readonly array $customGroups = [],
		public readonly array $years = [],
		public readonly array $signatures = [],
		public readonly array $sourceTypes = [],
		public readonly array $bookmarks = [],
		public readonly array $audioFiles = [],
		public readonly ?int $lastModified = null,
		public readonly ?int $dateCreated = null,
		public readonly ?int $stars = null,
		public readonly ?int $difficulty = null,
		public readonly ?int $duration = null,
		public readonly string $keywords = '',
		public readonly string $custom = '',
		public readonly string $custom2 = '',
		public readonly string $sortTitle = '',
		public readonly string $opus = '',
		public readonly string $catalogNumber = '',
		public readonly string $songNumber = '',
		public readonly array $instruments = [],
		public readonly array $arrangers = [],
	) {
	}
}
