<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileLogEntry;

/**
 * A song has no composer, artist or collection to build a file name from.
 *
 * This is a warning rather than an error: the file simply keeps its current name
 * because there is nothing to derive a new one from. Nothing is broken, so the
 * run keeps going and only reports it - with the stable code every report maps
 * to its localized wording.
 */
class NoNamingRuleException extends SyncException {
	public function __construct(string $message) {
		parent::__construct(MobileSheetsFileLogEntry::CODE_NO_NAMING_RULE, $message);
	}
}
