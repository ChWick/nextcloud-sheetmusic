<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

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
	 * @param list<MobileSheetsSongNote> $notes
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
		public readonly array $notes = [],
		public readonly ?int $lastModified = null,
		public readonly ?int $dateCreated = null,
		public readonly int $stars = 0,
		public readonly int $difficulty = 0,
		public readonly int $duration = 0,
		public readonly string $keywords = '',
		public readonly string $custom = '',
		public readonly string $custom2 = '',
		public readonly string $sortTitle = '',
	) {
	}
}
