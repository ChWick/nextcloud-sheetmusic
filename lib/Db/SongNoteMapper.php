<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use PDO;

class SongNoteMapper extends AbstractDatabaseMapper {
	/**
	 * @return list<MobileSheetsSongNote>
	 */
	public function notesOfSong(string $databasePath, int $songId): array {
		$database = $this->openConnection($databasePath);

		if (!$this->hasTable($database, 'SongNotes')) {
			return [];
		}

		$cols = $this->columnNames($database, 'SongNotes');
		$textCol = $this->resolveColumn($database, 'SongNotes', ['Note', 'Text'], 'Note');
		$createdCol = isset($cols['datecreated']) ? 'DateCreated' : (isset($cols['creationdate']) ? 'CreationDate' : null);

		$select = ['Id', 'SongId', $textCol . ' AS Note'];
		if ($createdCol !== null) {
			$select[] = $createdCol . ' AS DateCreated';
		}
		if (isset($cols['lastmodified'])) {
			$select[] = 'LastModified';
		}

		$stmt = $database->prepare('SELECT ' . implode(', ', $select) . ' FROM SongNotes WHERE SongId = :songId ORDER BY Id ASC');
		$stmt->execute([':songId' => $songId]);

		$notes = [];
		while ($row = $stmt->fetch()) {
			$notes[] = new MobileSheetsSongNote(
				(int)$row['Id'],
				(int)$row['SongId'],
				(string)($row['Note'] ?? ''),
				isset($row['DateCreated']) && $row['DateCreated'] !== null ? (int)$row['DateCreated'] : null,
				isset($row['LastModified']) && $row['LastModified'] !== null ? (int)$row['LastModified'] : null,
			);
		}

		return $notes;
	}

	/**
	 * @param list<array{note?: string, text?: string}> $notes
	 */
	public function updateNotes(string $databasePath, int $songId, array $notes): void {
		$database = $this->openConnection($databasePath);

		if (!$this->hasTable($database, 'SongNotes')) {
			return;
		}

		$stmt = $database->prepare('DELETE FROM SongNotes WHERE SongId = :songId');
		$stmt->execute([':songId' => $songId]);

		$textCol = $this->resolveColumn($database, 'SongNotes', ['Note', 'Text'], 'Note');

		foreach ($notes as $noteSpec) {
			$text = trim((string)($noteSpec['note'] ?? $noteSpec['text'] ?? ''));
			if ($text === '') {
				continue;
			}

			$this->insertRow($database, 'SongNotes', [
				'SongId' => $songId,
				$textCol => $text,
			] + $this->dateColumns($database, 'SongNotes'));
		}
	}
}
