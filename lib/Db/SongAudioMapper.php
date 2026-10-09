<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

class SongAudioMapper extends AbstractDatabaseMapper {
	/**
	 * @return list<MobileSheetsAudioFile>
	 */
	public function audioFilesOfSong(string $databasePath, int $songId): array {
		$database = $this->openConnection($databasePath);

		if (!$this->hasTable($database, 'AudioFiles')) {
			return [];
		}

		$titleCol = $this->resolveColumn($database, 'AudioFiles', ['Title', 'Name'], 'Title');
		$fileCol = $this->resolveColumn($database, 'AudioFiles', ['File', 'Path'], 'File');

		$stmt = $database->prepare('SELECT Id, SongId, ' . $titleCol . ' AS Title, ' . $fileCol . ' AS File FROM AudioFiles WHERE SongId = :songId ORDER BY Id ASC');
		$stmt->execute([':songId' => $songId]);

		$audioFiles = [];
		while ($row = $stmt->fetch()) {
			$audioFiles[] = new MobileSheetsAudioFile(
				(int)$row['Id'],
				(int)$row['SongId'],
				(string)($row['Title'] ?? ''),
				(string)($row['File'] ?? ''),
			);
		}

		return $audioFiles;
	}

	/**
	 * @param list<array{title?: string, file: string}> $audioFiles
	 */
	public function updateAudioFiles(string $databasePath, int $songId, array $audioFiles): void {
		$database = $this->openConnection($databasePath);

		if (!$this->hasTable($database, 'AudioFiles')) {
			return;
		}

		$stmt = $database->prepare('DELETE FROM AudioFiles WHERE SongId = :songId');
		$stmt->execute([':songId' => $songId]);

		foreach ($audioFiles as $af) {
			$file = trim((string)($af['file'] ?? ''));
			if ($file === '') {
				continue;
			}
			$title = trim((string)($af['title'] ?? ''));

			$this->insertRow($database, 'AudioFiles', [
				'SongId' => $songId,
				'Title' => $title !== '' ? $title : pathinfo($file, PATHINFO_FILENAME),
				'File' => $file,
			] + $this->dateColumns($database, 'AudioFiles'));
		}
	}
}
