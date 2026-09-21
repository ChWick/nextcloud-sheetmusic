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
	 * @param list<int> $composerIds
	 * @param list<int> $bookIds
	 * @param list<int> $collectionIds
	 * @param list<int> $artistIds
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly array $files = [],
		public readonly array $composerIds = [],
		public readonly array $bookIds = [],
		public readonly array $collectionIds = [],
		public readonly array $artistIds = [],
		public readonly ?string $composerName = null,
		public readonly ?string $artistName = null,
		public readonly ?string $bookName = null,
		public readonly ?string $collectionName = null,
	) {
	}
}