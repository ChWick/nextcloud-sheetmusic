<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;


class SongFileMapper extends AbstractDatabaseMapper {
	/** Source of a file that lives in the library folder. */
	private const FILE_SOURCE_LOCAL = 1;

	/** Type constants for MobileSheets Files.Type column */
	public const FILE_TYPE_OTHER = 0;
	public const FILE_TYPE_PDF = 1;
	public const FILE_TYPE_IMAGE = 2;
	public const FILE_TYPE_TEXT = 3;
	public const FILE_TYPE_ABC = 4;

	/** Width/Height of a file whose dimensions were not measured. */
	private const FILE_DIMENSION_UNKNOWN = -1;

	/**
	 * Map a file path's extension to MobileSheets Files.Type.
	 */
	public static function determineFileType(string $path): int {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return match ($extension) {
			'pdf' => self::FILE_TYPE_PDF,
			'png', 'jpg', 'jpeg', 'bmp', 'gif', 'webp', 'svg' => self::FILE_TYPE_IMAGE,
			'txt', 'cho', 'chopro', 'crd', 'chordpro' => self::FILE_TYPE_TEXT,
			'abc' => self::FILE_TYPE_ABC,
			default => self::FILE_TYPE_OTHER,
		};
	}

	/**
	 * @return list<MobileSheetsFile>
	 */
	public function filesOfSong(string $databasePath, int $songId): array {
		$database = $this->openConnection($databasePath);

		if (!$this->hasTable($database, 'Files')) {
			return [];
		}

		// Ordered by Id so a song's files always come back in the same order: their
		// position decides the numeric prefix in the regenerated file name.
		$statement = $database->query('SELECT Id, Path FROM Files WHERE SongId = ' . $songId . ' ORDER BY Id ASC');

		$files = [];
		if ($statement !== false) {
			while ($row = $statement->fetch()) {
				$files[] = new MobileSheetsFile(
					(int)$row['Id'],
					(string)$row['Path'],
				);
			}
		}

		return $files;
	}

	/**
	 * Record a file of a song, filling in every column MobileSheets keeps next to
	 * the path: the file's size, its modification time (LastModified, in
	 * milliseconds) and the derived content metadata.
	 */
	public function setSongFile(string $databasePath, int $songId, string $filePath, MobileSheetsFileFacts $facts): void {
		$database = $this->openConnection($databasePath);

		// Files carries no creation date, but its LastModified is required.
		$this->requireColumn($database, 'Files', 'LastModified');

		$values = [
			'SongId' => $songId,
			'Path' => $filePath,
			'PageOrder' => $facts->pageOrder,
			'FileSize' => $facts->fileSize,
			'LastModified' => $facts->lastModified,
			'Source' => self::FILE_SOURCE_LOCAL,
			'Type' => self::determineFileType($filePath),
			'Password' => '',
			'SourceFilePageCount' => $facts->sourceFilePageCount,
			'FileHash' => $facts->fileHash,
			'Width' => self::FILE_DIMENSION_UNKNOWN,
			'Height' => self::FILE_DIMENSION_UNKNOWN,
		];

		$columns = [];
		$params = [];
		foreach ($this->existingColumns($database, 'Files', $values) as $name => $value) {
			$columns[] = $name;
			$params[':' . $name] = $value;
		}

		$sql = 'INSERT INTO Files (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($params)) . ')';
		$insertStmt = $database->prepare($sql);
		$insertStmt->execute($params);
	}

	public function removeSongFile(string $databasePath, int $songId, int $fileId): ?string {
		$database = $this->openConnection($databasePath);

		$stmt = $database->prepare('SELECT Path FROM Files WHERE SongId = :songId AND Id = :fileId');
		$stmt->execute([':songId' => $songId, ':fileId' => $fileId]);
		$row = $stmt->fetch();
		if ($row === false) {
			return null;
		}

		$delStmt = $database->prepare('DELETE FROM Files WHERE SongId = :songId AND Id = :fileId');
		$delStmt->execute([':songId' => $songId, ':fileId' => $fileId]);

		return (string)$row['Path'];
	}

	/**
	 * Persist a file's new location and refresh the metadata that a change to the
	 * file invalidates - notably LastModified, which must track the file's
	 * modification time whenever the file is created or changed. Source, Password,
	 * Width and Height are fixed at creation and left untouched. Type is updated
	 * if the file extension changed.
	 */
	public function updateFile(string $databasePath, int $fileId, string $newPath, MobileSheetsFileFacts $facts): void {
		$database = $this->openConnection($databasePath);

		// Files carries no creation date, but its LastModified is required.
		$this->requireColumn($database, 'Files', 'LastModified');

		$values = [
			'Path' => $newPath,
			'Type' => self::determineFileType($newPath),
			'PageOrder' => $facts->pageOrder,
			'FileSize' => $facts->fileSize,
			'LastModified' => $facts->lastModified,
			'SourceFilePageCount' => $facts->sourceFilePageCount,
			'FileHash' => $facts->fileHash,
		];

		$assignments = [];
		$params = [':id' => $fileId];
		foreach ($this->existingColumns($database, 'Files', $values) as $name => $value) {
			$assignments[] = $name . ' = :' . $name;
			$params[':' . $name] = $value;
		}

		$sql = 'UPDATE Files SET ' . implode(', ', $assignments) . ' WHERE Id = :id';
		$updateStmt = $database->prepare($sql);
		$updateStmt->execute($params);
	}

	public function isPathReferencedByOtherSong(string $databasePath, string $filePath, int $songId): bool {
		$database = $this->openConnection($databasePath);

		if (!$this->hasTable($database, 'Files')) {
			return false;
		}

		$cleanPath = strtolower(ltrim($filePath, '/'));
		$stmt = $database->prepare('SELECT COUNT(*) FROM Files WHERE LOWER(LTRIM(Path, "/")) = :path AND SongId != :songId');
		$stmt->execute([':path' => $cleanPath, ':songId' => $songId]);
		return ((int)$stmt->fetchColumn()) > 0;
	}
}
