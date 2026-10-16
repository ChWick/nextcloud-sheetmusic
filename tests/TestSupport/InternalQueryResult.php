<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCP\DB\IResult;

if (!interface_exists(InternalQueryResult::class)) {
	interface InternalQueryResult extends IResult {
		public function fetchAssociative(): array|false;
		public function fetchAllAssociative(): array;
	}
}

