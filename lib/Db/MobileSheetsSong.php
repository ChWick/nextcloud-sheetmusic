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
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly array $files = [],
		public readonly array $composers = [],
		public readonly array $books = [],
		public readonly array $collections = [],
		public readonly array $artists = [],
	) {
	}
}
