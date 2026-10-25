<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCA\SheetMusic\Db\LibraryData;
use PDO;
use Psr\Log\LoggerInterface;

class SongMapper extends AbstractDatabaseMapper {
	/** Type constants for MobileSheets Files.Type column */
	public const FILE_TYPE_OTHER = SongFileMapper::FILE_TYPE_OTHER;
	public const FILE_TYPE_PDF = SongFileMapper::FILE_TYPE_PDF;
	public const FILE_TYPE_IMAGE = SongFileMapper::FILE_TYPE_IMAGE;
	public const FILE_TYPE_TEXT = SongFileMapper::FILE_TYPE_TEXT;
	public const FILE_TYPE_ABC = SongFileMapper::FILE_TYPE_ABC;

	private SongFileMapper $fileMapper;
	private SongBookmarkMapper $bookmarkMapper;
	private SongAudioMapper $audioMapper;
	private LibraryEntityMapper $entityMapper;
	private SongNoteMapper $noteMapper;
	private SongReader $songReader;

	public function __construct(
		LoggerInterface $logger,
		?SongFileMapper $fileMapper = null,
		?SongBookmarkMapper $bookmarkMapper = null,
		?SongAudioMapper $audioMapper = null,
		?LibraryEntityMapper $entityMapper = null,
		?SongNoteMapper $noteMapper = null,
		?SongReader $songReader = null,
	) {
		parent::__construct($logger);
		$this->fileMapper = $fileMapper ?? new SongFileMapper($logger);
		$this->bookmarkMapper = $bookmarkMapper ?? new SongBookmarkMapper($logger);
		$this->audioMapper = $audioMapper ?? new SongAudioMapper($logger);
		$this->entityMapper = $entityMapper ?? new LibraryEntityMapper($logger);
		$this->noteMapper = $noteMapper ?? new SongNoteMapper($logger);
		$this->songReader = $songReader ?? new SongReader(
			$logger,
			$this->fileMapper,
			$this->bookmarkMapper,
			$this->audioMapper,
		);
	}

	public static function determineFileType(string $path): int {
		return SongFileMapper::determineFileType($path);
	}

	/**
	 * @return LibraryData
	 */
	public function findLibraryData(string $databasePath): LibraryData {
		return $this->songReader->findLibraryData($databasePath);
	}

	/**
	 * @param int|null $songId when given, only that song is returned
	 * @return list<MobileSheetsSong>
	 */
	public function findSongs(string $databasePath, ?int $songId = null): array {
		return $this->songReader->findSongs($databasePath, $songId);
	}

	public function findSong(string $databasePath, int $songId): ?MobileSheetsSong {
		return $this->songReader->findSong($databasePath, $songId);
	}

	/**
	 * @return list<MobileSheetsFile>
	 */
	public function filesOfSong(string $databasePath, int $songId): array {
		return $this->fileMapper->filesOfSong($databasePath, $songId);
	}

	/**
	 * @return list<MobileSheetsBookmark>
	 */
	public function bookmarksOfSong(string $databasePath, int $songId): array {
		return $this->bookmarkMapper->bookmarksOfSong($databasePath, $songId);
	}

	/**
	 * @param list<array{name: string, pageNum: int, showInLibrary?: int}> $bookmarks
	 */
	public function updateBookmarks(string $databasePath, int $songId, array $bookmarks): void {
		$this->bookmarkMapper->updateBookmarks($databasePath, $songId, $bookmarks);
	}

	/**
	 * @return list<MobileSheetsAudioFile>
	 */
	public function audioFilesOfSong(string $databasePath, int $songId): array {
		return $this->audioMapper->audioFilesOfSong($databasePath, $songId);
	}

	/**
	 * @param list<array{title?: string, file: string}> $audioFiles
	 */
	public function updateAudioFiles(string $databasePath, int $songId, array $audioFiles): void {
		$this->audioMapper->updateAudioFiles($databasePath, $songId, $audioFiles);
	}

	/**
	 * @return list<MobileSheetsSongNote>
	 */
	public function notesOfSong(string $databasePath, int $songId): array {
		return $this->noteMapper->notesOfSong($databasePath, $songId);
	}

	/**
	 * @param list<array{note?: string, text?: string}> $notes
	 */
	public function updateNotes(string $databasePath, int $songId, array $notes): void {
		$this->noteMapper->updateNotes($databasePath, $songId, $notes);
	}

	/**
	 * Create a song in the MobileSheets database.
	 *
	 * @param list<int> $composerIds
	 * @param list<int> $bookIds
	 * @param list<int> $collectionIds
	 * @param list<int> $artistIds
	 * @param list<int> $genreIds
	 * @param list<int> $keyIds
	 * @param list<int> $customGroupIds
	 * @param list<int> $yearIds
	 * @param list<int> $signatureIds
	 * @param list<int> $sourceTypeIds
	 * @return int id of the created song
	 */
	public function createSong(
		string $databasePath,
		string $title,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
		array $genreIds = [],
		array $keyIds = [],
		array $customGroupIds = [],
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
		int $stars = 0,
		int $difficulty = 0,
		int $duration = 0,
		string $keywords = '',
		string $custom = '',
		string $custom2 = '',
		string $sortTitle = '',
		string $opus = '',
		string $catalogNumber = '',
		string $songNumber = '',
		array $instrumentIds = [],
	): int {
		$database = $this->openConnection($databasePath);

		$songCols = $this->columnNames($database, 'Songs');
		$data = [
			'Title' => $title,
			'SortTitle' => $sortTitle !== '' ? $sortTitle : $title,
			'Stars' => $stars,
			'Difficulty' => $difficulty,
			'Duration' => $duration,
			'Keywords' => $keywords,
			'Custom' => $custom,
			'Custom2' => $custom2,
		];
		if (isset($songCols['opus'])) {
			$data['Opus'] = $opus;
		}
		if (isset($songCols['catalognumber'])) {
			$data['CatalogNumber'] = $catalogNumber;
		}
		if (isset($songCols['songnumber'])) {
			$data['SongNumber'] = $songNumber;
		}

		$songId = $this->insertRow($database, 'Songs', $data + $this->dateColumns($database, 'Songs'));

		$this->updateSong(
			$databasePath,
			$songId,
			$title,
			$composerIds,
			$bookIds,
			$collectionIds,
			$artistIds,
			$genreIds,
			$keyIds,
			$customGroupIds,
			$yearIds,
			$signatureIds,
			$sourceTypeIds,
			$stars,
			$difficulty,
			$duration,
			$keywords,
			$custom,
			$custom2,
			$sortTitle,
			$opus,
			$catalogNumber,
			$songNumber,
			$instrumentIds,
		);

		return $songId;
	}

	/**
	 * @param list<int> $composerIds
	 * @param list<int> $bookIds
	 * @param list<int> $collectionIds
	 * @param list<int> $artistIds
	 * @param list<int> $genreIds
	 * @param list<int> $keyIds
	 * @param list<int> $customGroupIds
	 * @param list<int> $yearIds
	 * @param list<int> $signatureIds
	 * @param list<int> $sourceTypeIds
	 * @param list<int> $instrumentIds
	 */
	public function updateSong(
		string $databasePath,
		int $songId,
		string $title,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
		array $genreIds = [],
		array $keyIds = [],
		array $customGroupIds = [],
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
		int $stars = 0,
		int $difficulty = 0,
		int $duration = 0,
		string $keywords = '',
		string $custom = '',
		string $custom2 = '',
		string $sortTitle = '',
		string $opus = '',
		string $catalogNumber = '',
		string $songNumber = '',
		array $instrumentIds = [],
	): void {
		$database = $this->openConnection($databasePath);

		$songCols = $this->columnNames($database, 'Songs');
		$updateData = [
			'Title' => $title,
			'SortTitle' => $sortTitle !== '' ? $sortTitle : $title,
			'Stars' => $stars,
			'Difficulty' => $difficulty,
			'Duration' => $duration,
			'Keywords' => $keywords,
			'Custom' => $custom,
			'Custom2' => $custom2,
		];
		if (isset($songCols['opus'])) {
			$updateData['Opus'] = $opus;
		}
		if (isset($songCols['catalognumber'])) {
			$updateData['CatalogNumber'] = $catalogNumber;
		}
		if (isset($songCols['songnumber'])) {
			$updateData['SongNumber'] = $songNumber;
		}

		$this->updateRowById($database, 'Songs', $updateData + $this->dateColumns($database, 'Songs', withCreation: false), $songId);

		$this->replaceSongRelations($database, 'ComposerSongs', 'ComposerId', $songId, $composerIds);
		$this->replaceSongRelations($database, 'BookSongs', 'BookId', $songId, $bookIds);
		$this->replaceSongRelations($database, 'CollectionSong', 'CollectionId', $songId, $collectionIds);
		$this->replaceSongRelations($database, 'ArtistsSongs', 'ArtistId', $songId, $artistIds);
		$this->replaceSongRelations($database, 'GenresSongs', 'GenreId', $songId, $genreIds);
		$this->replaceSongRelations($database, 'KeySongs', 'KeyId', $songId, $keyIds);
		$this->replaceSongRelations($database, 'CustomGroupSongs', 'GroupId', $songId, array_values(array_unique(array_merge($customGroupIds, $instrumentIds))));
		$this->replaceSongRelations($database, 'YearsSongs', 'YearId', $songId, $yearIds);
		$this->replaceSongRelations($database, 'SignatureSongs', 'SignatureId', $songId, $signatureIds);
		$this->replaceSongRelations($database, 'SourceTypeSongs', 'SourceTypeId', $songId, $sourceTypeIds);
	}

	/**
	 * Batch update metadata for multiple songs.
	 *
	 * @param list<int> $songIds
	 * @param array{action?: string, ids?: list<int>}|null $composerUpdate
	 * @param array{action?: string, ids?: list<int>}|null $bookUpdate
	 * @param array{action?: string, ids?: list<int>}|null $collectionUpdate
	 * @param array{action?: string, ids?: list<int>}|null $artistUpdate
	 * @param array{action?: string, ids?: list<int>}|null $genreUpdate
	 * @param array{action?: string, ids?: list<int>}|null $keyUpdate
	 * @param array{action?: string, ids?: list<int>}|null $customGroupUpdate
	 * @param array{action?: string, ids?: list<int>}|null $yearUpdate
	 * @param array{action?: string, ids?: list<int>}|null $signatureUpdate
	 * @param array{action?: string, ids?: list<int>}|null $sourceTypeUpdate
	 */
	public function batchUpdateSongs(
		string $databasePath,
		array $songIds,
		?array $composerUpdate = null,
		?array $bookUpdate = null,
		?array $collectionUpdate = null,
		?array $artistUpdate = null,
		?array $genreUpdate = null,
		?array $keyUpdate = null,
		?array $customGroupUpdate = null,
		?array $yearUpdate = null,
		?array $signatureUpdate = null,
		?array $sourceTypeUpdate = null,
		?array $instrumentUpdate = null,
	): void {
		if (empty($songIds)) {
			return;
		}

		$database = $this->openConnection($databasePath);

		$allUpdates = [
			['ComposerSongs', 'ComposerId', $composerUpdate],
			['BookSongs', 'BookId', $bookUpdate],
			['CollectionSong', 'CollectionId', $collectionUpdate],
			['ArtistsSongs', 'ArtistId', $artistUpdate],
			['GenresSongs', 'GenreId', $genreUpdate],
			['KeySongs', 'KeyId', $keyUpdate],
			['CustomGroupSongs', 'GroupId', $customGroupUpdate],
			['YearsSongs', 'YearId', $yearUpdate],
			['SignatureSongs', 'SignatureId', $signatureUpdate],
			['SourceTypeSongs', 'SourceTypeId', $sourceTypeUpdate],
			['CustomGroupSongs', 'GroupId', $instrumentUpdate],
		];
		$activeUpdates = array_values(array_filter(
			$allUpdates,
			fn (array $u): bool => $u[2] !== null && $this->hasTable($database, $u[0]),
		));

		$database->beginTransaction();
		try {
			foreach ($songIds as $songId) {
				$this->updateRowById($database, 'Songs', $this->dateColumns($database, 'Songs', withCreation: false), $songId);

				foreach ($activeUpdates as [$table, $col, $spec]) {
					$this->updateCategoryRelations($database, $table, $col, $songId, $spec);
				}
			}
			$database->commit();
		} catch (\Throwable $e) {
			$database->rollBack();
			throw $e;
		}
	}

	public function createInstrument(string $databasePath, string $name): int {
		return $this->entityMapper->createInstrument($databasePath, $name);
	}

	private function updateCategoryRelations(
		PDO $database,
		string $tableName,
		string $entityIdCol,
		int $songId,
		array $updateSpec,
	): void {
		$action = $updateSpec['action'] ?? 'add';
		$targetIds = array_map('intval', $updateSpec['ids'] ?? []);

		if ($action === 'replace') {
			$delStmt = $database->prepare('DELETE FROM "' . $tableName . '" WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO "' . $tableName . '" (' . $entityIdCol . ', SongId) VALUES (:entityId, :songId)');
			foreach ($targetIds as $entityId) {
				$insStmt->execute([':entityId' => $entityId, ':songId' => $songId]);
			}
		} elseif ($action === 'add') {
			$existingStmt = $database->prepare('SELECT ' . $entityIdCol . ' FROM "' . $tableName . '" WHERE SongId = :songId');
			$existingStmt->execute([':songId' => $songId]);
			$existing = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

			$insStmt = $database->prepare('INSERT INTO "' . $tableName . '" (' . $entityIdCol . ', SongId) VALUES (:entityId, :songId)');
			foreach ($targetIds as $entityId) {
				if (!in_array($entityId, $existing, true)) {
					$insStmt->execute([':entityId' => $entityId, ':songId' => $songId]);
				}
			}
		} elseif ($action === 'remove') {
			if (!empty($targetIds)) {
				$inClause = implode(',', array_fill(0, count($targetIds), '?'));
				$delStmt = $database->prepare('DELETE FROM "' . $tableName . '" WHERE SongId = ? AND ' . $entityIdCol . ' IN (' . $inClause . ')');
				$delStmt->execute(array_merge([$songId], $targetIds));
			}
		}
	}

	/**
	 * Replace song-entity relations in a link table.
	 *
	 * @param list<int> $entityIds
	 */
	private function replaceSongRelations(PDO $database, string $tableName, string $entityIdCol, int $songId, array $entityIds): void {
		if (!$this->hasTable($database, $tableName)) {
			return;
		}

		$delStmt = $database->prepare('DELETE FROM "' . $tableName . '" WHERE SongId = :songId');
		$delStmt->execute([':songId' => $songId]);

		if ($entityIds === []) {
			return;
		}

		$insStmt = $database->prepare('INSERT INTO "' . $tableName . '" (' . $entityIdCol . ', SongId) VALUES (:entityId, :songId)');
		foreach ($entityIds as $entityId) {
			$insStmt->execute([
				':entityId' => $entityId,
				':songId' => $songId,
			]);
		}
	}

	/**
	 * Merge two songs: re-point all SongId references from $droppedId to $keepId,
	 * de-duplicate relation rows, then delete the dropped song row.
	 *
	 * All tables that have a SongId column (except Songs itself) are updated.
	 * The Songs row for $keepId is left untouched – the caller is responsible for
	 * updating its fields beforehand with updateSong() if needed.
	 */
	public function mergeSongs(string $databasePath, int $keepId, int $droppedId): void {
		if ($keepId === $droppedId) {
			return;
		}

		$database = $this->openConnection($databasePath);
		$schema = $this->getSchemaMap($database);
		$existingTables = array_values($schema['tables']);

		$database->beginTransaction();
		try {
			foreach ($existingTables as $table) {
				$lc = strtolower($table);
				if ($lc === 'songs' || !$this->hasSongIdColumn($database, $table)) {
					continue;
				}

				// Fetch all columns so we can build a unique-key check for relation tables
				$cols = array_values($this->columnNames($database, $table));

				// For pure relation tables (2 columns: SongId + entity FK) we move
				// only the rows that are not already present for $keepId.
				if (count($cols) === 2) {
					$otherCol = strtolower($cols[0]) === 'songid' ? $cols[1] : $cols[0];

					// Find entity IDs already linked to $keepId
					$existStmt = $database->prepare(
						'SELECT "' . $otherCol . '" FROM "' . $table . '" WHERE SongId = :keepId'
					);
					$existStmt->execute([':keepId' => $keepId]);
					$existing = array_map('intval', $existStmt->fetchAll(PDO::FETCH_COLUMN));

					// Move references that are not yet linked to $keepId
					$moveStmt = $database->prepare(
						'UPDATE "' . $table . '" SET SongId = :keepId'
						. ' WHERE SongId = :droppedId AND "' . $otherCol . '" NOT IN ('
						. 'SELECT "' . $otherCol . '" FROM "' . $table . '" WHERE SongId = :keepId2'
						. ')'
					);
					$moveStmt->execute([
						':keepId' => $keepId,
						':droppedId' => $droppedId,
						':keepId2' => $keepId,
					]);

					// Remove remaining (duplicate) rows for $droppedId
					$delStmt = $database->prepare(
						'DELETE FROM "' . $table . '" WHERE SongId = :droppedId'
					);
					$delStmt->execute([':droppedId' => $droppedId]);
				} else {
					// Other tables (e.g. Files, Bookmarks, AudioFiles, Notes…)
					// just reassign the SongId.
					$updateStmt = $database->prepare(
						'UPDATE "' . $table . '" SET SongId = :keepId WHERE SongId = :droppedId'
					);
					$updateStmt->execute([':keepId' => $keepId, ':droppedId' => $droppedId]);
				}
			}

			// Delete the dropped Songs row
			if ($this->hasTable($database, 'Songs')) {
				$stmt = $database->prepare('DELETE FROM Songs WHERE Id = :droppedId');
				$stmt->execute([':droppedId' => $droppedId]);
			}

			$database->commit();
		} catch (\Throwable $exception) {
			$database->rollBack();
			throw $exception;
		}
	}

	public function deleteSong(string $databasePath, int $songId): array {
		$database = $this->openConnection($databasePath);

		$schema = $this->getSchemaMap($database);
		$existingTables = array_values($schema['tables']);

		$paths = [];
		if ($this->hasTable($database, 'Files')) {
			$fileStmt = $database->prepare('SELECT Path FROM Files WHERE SongId = :songId');
			$fileStmt->execute([':songId' => $songId]);
			while ($row = $fileStmt->fetch()) {
				$paths[] = (string)$row['Path'];
			}
		}

		$database->beginTransaction();
		try {
			foreach ($existingTables as $table) {
				if (strtolower($table) === 'songs' || !$this->hasSongIdColumn($database, $table)) {
					continue;
				}

				$stmt = $database->prepare('DELETE FROM "' . $table . '" WHERE SongId = :songId');
				$stmt->execute([':songId' => $songId]);
			}

			if ($this->hasTable($database, 'Songs')) {
				$stmt = $database->prepare('DELETE FROM Songs WHERE Id = :songId');
				$stmt->execute([':songId' => $songId]);
			}

			$database->commit();
		} catch (\Throwable $exception) {
			$database->rollBack();
			throw $exception;
		}

		return $paths;
	}

	private function hasSongIdColumn(PDO $database, string $table): bool {
		$cols = $this->columnNames($database, $table);
		return isset($cols['songid']);
	}

	public function setSongFile(string $databasePath, int $songId, string $filePath, MobileSheetsFileFacts $facts): void {
		$this->fileMapper->setSongFile($databasePath, $songId, $filePath, $facts);
	}

	public function removeSongFile(string $databasePath, int $songId, int $fileId): ?string {
		return $this->fileMapper->removeSongFile($databasePath, $songId, $fileId);
	}

	public function updateFile(string $databasePath, int $fileId, string $newPath, MobileSheetsFileFacts $facts): void {
		$this->fileMapper->updateFile($databasePath, $fileId, $newPath, $facts);
	}

	public function createComposer(string $databasePath, string $name): int {
		return $this->entityMapper->createComposer($databasePath, $name);
	}

	public function createBook(string $databasePath, string $title): int {
		return $this->entityMapper->createBook($databasePath, $title);
	}

	public function createCollection(string $databasePath, string $name): int {
		return $this->entityMapper->createCollection($databasePath, $name);
	}

	public function createArtist(string $databasePath, string $name): int {
		return $this->entityMapper->createArtist($databasePath, $name);
	}

	public function createGenre(string $databasePath, string $name): int {
		return $this->entityMapper->createGenre($databasePath, $name);
	}

	public function createKey(string $databasePath, string $name): int {
		return $this->entityMapper->createKey($databasePath, $name);
	}

	public function createCustomGroup(string $databasePath, string $name): int {
		return $this->entityMapper->createCustomGroup($databasePath, $name);
	}

	public function createYear(string $databasePath, int|string $year): int {
		return $this->entityMapper->createYear($databasePath, $year);
	}

	public function createSignature(string $databasePath, string $name): int {
		return $this->entityMapper->createSignature($databasePath, $name);
	}

	public function createSourceType(string $databasePath, string $name): int {
		return $this->entityMapper->createSourceType($databasePath, $name);
	}

	public function deleteEntity(string $databasePath, string $entityType, int $entityId): bool {
		return $this->entityMapper->deleteEntity($databasePath, $entityType, $entityId);
	}

	public function isPathReferencedByOtherSong(string $databasePath, string $filePath, int $songId): bool {
		return $this->fileMapper->isPathReferencedByOtherSong($databasePath, $filePath, $songId);
	}
}
