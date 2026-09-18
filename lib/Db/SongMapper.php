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
	 * @return list<array{id: int, title: string}>
	 */
	public function findSongs(string $databasePath): array {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$statement = $database->query('SELECT Songs.Id, Songs.Title, Files.Path AS FilePath FROM Songs LEFT JOIN Files ON Files.SongId = Songs.Id ORDER BY Songs.SortTitle, Songs.Title');

		$songs = [];
		while ($row = $statement->fetch()) {
			$songs[] = [
				'id' => (int)$row['Id'],
				'title' => (string)$row['Title'],
				'filePath' => $row['FilePath'] !== null ? (string)$row['FilePath'] : null,
			];
		}

		return $songs;
	}

}