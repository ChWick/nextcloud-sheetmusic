<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

/**
 * A problem the sync tool knows by name, reported with a stable code.
 *
 * The message explains the problem to a reader of the Nextcloud log; the code is
 * what the report dialog works with. It looks up the localized wording and, more
 * importantly, lets the user jump to the file the entry is about - a human
 * message cannot be parsed reliably, a code can. The grade (info/warning/error)
 * follows from the code, so the same problem is always reported the same way,
 * no matter which phase of a run found it.
 *
 * @see MobileSheetsFileLogEntry::levelForCode() for the code => grade mapping
 * @see MobileSheetsFileLogEntry for the list of codes
 */
class SyncException extends \RuntimeException {
	public function __construct(
		private readonly string $errorCode,
		string $message,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/** The stable code of this problem, e.g. "source-missing". */
	public function errorCode(): string {
		return $this->errorCode;
	}

	/** The grade the report gives to this problem: warning or error. */
	public function errorLevel(): string {
		return MobileSheetsFileLogEntry::levelForCode($this->errorCode);
	}
}
