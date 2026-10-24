<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

class SongBookmarkMapper extends AbstractDatabaseMapper {
	/**
	 * @return list<MobileSheetsBookmark>
	 */
	public function bookmarksOfSong(string $databasePath, int $songId): array {
		$database = $this->openConnection($databasePath);

		if (!$this->hasTable($database, 'Bookmarks')) {
			return [];
		}

		$cols = $this->columnNames($database, 'Bookmarks');
		$hasShowInLibrary = isset($cols['showinlibrary']);

		$stmt = $database->prepare('SELECT Id, SongId, Name, PageNum' . ($hasShowInLibrary ? ', ShowInLibrary' : '') . ' FROM Bookmarks WHERE SongId = :songId ORDER BY PageNum ASC, Name ASC');
		$stmt->execute([':songId' => $songId]);

		$bookmarks = [];
		while ($row = $stmt->fetch()) {
			$bookmarks[] = new MobileSheetsBookmark(
				(int)$row['Id'],
				(int)$row['SongId'],
				(string)$row['Name'],
				(int)$row['PageNum'],
				isset($row['ShowInLibrary']) ? (int)$row['ShowInLibrary'] : 0,
			);
		}

		return $bookmarks;
	}

	/**
	 * @param list<array{name: string, pageNum: int, showInLibrary?: int}> $bookmarks
	 */
	public function updateBookmarks(string $databasePath, int $songId, array $bookmarks): void {
		$database = $this->openConnection($databasePath);

		if (!$this->hasTable($database, 'Bookmarks')) {
			return;
		}

		$stmt = $database->prepare('DELETE FROM Bookmarks WHERE SongId = :songId');
		$stmt->execute([':songId' => $songId]);

		foreach ($bookmarks as $bm) {
			$name = trim((string)($bm['name'] ?? ''));
			if ($name === '') {
				continue;
			}
			$pageNum = (int)($bm['pageNum'] ?? 1);
			$showInLibrary = (int)($bm['showInLibrary'] ?? 0);

			$this->insertRow($database, 'Bookmarks', [
				'SongId' => $songId,
				'Name' => $name,
				'PageNum' => $pageNum,
				'ShowInLibrary' => $showInLibrary,
			] + $this->dateColumns($database, 'Bookmarks'));
		}
	}
}
