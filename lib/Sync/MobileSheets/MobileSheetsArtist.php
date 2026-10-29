<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

class MobileSheetsArtist {
	public function __construct(
		public readonly int $id,
		public readonly string $name,
	) {
	}
}
