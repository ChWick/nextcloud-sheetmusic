<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use PDO;

class SongMapper {
	/**
	 * @return list<string>
	 */
	public function findTitles(string $databasePath): array {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$statement = $database->query('SELECT Title FROM Songs ORDER BY SortTitle, Title');

		$titles = [];
		while ($row = $statement->fetch()) {
			$titles[] = (string)$row['Title'];
		}

		return $titles;
	}
}