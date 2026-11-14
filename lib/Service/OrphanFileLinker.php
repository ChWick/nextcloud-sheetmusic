<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileFacts;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsHashCodesFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsTableHelper;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Links an orphan file on disk to its uniquely matched song, updates both the
 * internal database and any cached MobileSheets SQLite database, and reapplies
 * library naming rules when autoPaths is enabled.
 */
final class OrphanFileLinker {
	public function __construct(
		private LibraryService $libraryService,
		private FileManager $fileManager,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
		private InternalSongMapper $internalSongMapper,
		private InternalSongFileMapper $internalSongFileMapper,
		private LibraryMapper $libraryMapper,
		private LibraryOrphanScanner $orphanScanner,
	) {
	}

	/**
	 * @return array{success: bool, fixedCount: int, message: string}
	 */
	public function linkOrphanFile(
		int $libraryId,
		string $userId,
		IUser $user,
		string $databasePath,
		Folder $targetFolder,
		string $path,
		?int $songId = null,
	): array {
		$cleanPath = ltrim(str_replace('\\', '/', $path), '/');
		try {
			$orphanNode = $targetFolder->get($cleanPath);
		} catch (\Throwable) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => sprintf('Orphan file "%s" was not found in library storage.', basename($path)),
			];
		}

		if (!($orphanNode instanceof Node)) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => sprintf('Orphan file "%s" is not a valid file.', basename($path)),
			];
		}

		$targetFolderPath = (string)$targetFolder->getPath();
		$songs = $this->internalSongMapper->findLibraryData($libraryId, $user)->songs;
		$missingSongIds = $this->orphanScanner->findMissingSongIds($songs, $targetFolder, $targetFolderPath, $user);
		$candidateSong = $this->orphanScanner->findOrphanFileCandidateSong($cleanPath, $songs, $missingSongIds);

		if ($candidateSong === null) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => sprintf('Could not uniquely identify a candidate song without files for "%s".', basename($path)),
			];
		}

		if ($songId !== null && $candidateSong->id !== $songId) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => sprintf('Candidate song ID %d does not match requested song ID %d.', $candidateSong->id, $songId),
			];
		}

		$missingFiles = $this->findMissingFilesForSong($candidateSong, $user, $targetFolder, $targetFolderPath);
		$this->linkInternalSongFile($libraryId, $candidateSong, $orphanNode, $missingFiles);
		$this->syncLinkedFileToSqlite($databasePath, $candidateSong, $orphanNode, $cleanPath, $user, $targetFolderPath, $missingFiles);
		$this->regenerateSongPathsIfEnabled($libraryId, $userId, $user, $targetFolder, $databasePath, $candidateSong->id);

		return [
			'success' => true,
			'fixedCount' => 1,
			'message' => sprintf('Linked orphan file "%s" to song "%s".', $orphanNode->getName(), $candidateSong->title),
		];
	}

	/**
	 * @return list<MobileSheetsFile>
	 */
	private function findMissingFilesForSong(MobileSheetsSong $song, IUser $user, Folder $targetFolder, string $targetFolderPath): array {
		$missingFiles = [];
		foreach ($song->files as $f) {
			$pathObj = $this->pathResolver->fromStored($f->path, $user);
			if ($pathObj === null) {
				$missingFiles[] = $f;
				continue;
			}
			$rel = $this->pathResolver->relativeToFolder($pathObj->internal, $targetFolderPath);
			if ($rel === null || !$targetFolder->nodeExists($rel)) {
				$missingFiles[] = $f;
			}
		}
		return $missingFiles;
	}

	/**
	 * @param list<MobileSheetsFile> $missingFiles
	 */
	private function linkInternalSongFile(int $libraryId, MobileSheetsSong $candidateSong, Node $orphanNode, array $missingFiles): void {
		$fileId = (int)$orphanNode->getId();
		foreach ($this->internalSongFileMapper->findAllForSong($candidateSong->id) as $ef) {
			if ($ef->fileId === $fileId) {
				return;
			}
		}

		foreach ($missingFiles as $mf) {
			$rec = $this->internalSongFileMapper->findById($mf->id);
			if ($rec !== null && $rec->songId === $candidateSong->id) {
				$this->internalSongFileMapper->delete($rec->id);
			}
		}

		$fileSize = 0;
		try {
			$fileSize = $orphanNode->getSize();
		} catch (\Throwable) {
		}

		$internalSongFile = new InternalSongFile(
			id: null,
			uuid: InternalEntity::generateUuid(),
			songId: $candidateSong->id,
			fileId: $fileId,
			fileSize: $fileSize,
			type: InternalSongFile::determineFileType($orphanNode->getName()),
			createdAt: time(),
			updatedAt: time(),
		);
		$this->internalSongFileMapper->insert($internalSongFile);
		$this->libraryMapper->touch($libraryId);
	}

	/**
	 * @param list<MobileSheetsFile> $missingFiles
	 */
	private function syncLinkedFileToSqlite(
		string $databasePath,
		MobileSheetsSong $candidateSong,
		Node $orphanNode,
		string $cleanPath,
		IUser $user,
		string $targetFolderPath,
		array $missingFiles,
	): void {
		if ($databasePath === '' || !file_exists($databasePath)) {
			return;
		}

		try {
			$db = new \PDO('sqlite:' . $databasePath);
			$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Files'")->fetchAll();
			if (empty($tables)) {
				return;
			}

			$internalSong = $this->internalSongMapper->findById($candidateSong->id);
			$sqliteSongId = $internalSong?->clientId;
			if ($sqliteSongId === null && MobileSheetsTableHelper::tableExists($db, 'Songs')) {
				$check = $db->prepare('SELECT Id FROM Songs WHERE Id = :id');
				$check->execute([':id' => $candidateSong->id]);
				if ($check->fetch() !== false) {
					$sqliteSongId = $candidateSong->id;
				}
			}

			if ($sqliteSongId === null) {
				return;
			}

			$facts = MobileSheetsFileFacts::fromNode($orphanNode);
			$fileType = InternalSongFile::determineFileType($cleanPath);
			$updatedExisting = false;

			foreach ($missingFiles as $mf) {
				$mfPathObj = $this->pathResolver->fromStored($mf->path, $user);
				$mfRel = ($mfPathObj !== null ? $this->pathResolver->relativeToFolder($mfPathObj->internal, $targetFolderPath) : null)
					?? basename($mf->path);

				$stmtFind = $db->prepare('SELECT Id FROM Files WHERE SongId = :songId AND (Path = :p1 OR Path = :p2 OR Id = :fid)');
				$stmtFind->execute([
					':songId' => $sqliteSongId,
					':p1' => $mfRel,
					':p2' => $mf->path,
					':fid' => $mf->id,
				]);
				$row = $stmtFind->fetch();
				if ($row !== false) {
					$stmtUpdate = $db->prepare('UPDATE Files SET Path = :path, PageOrder = :pageOrder, FileSize = :fileSize, LastModified = :lastModified, Type = :type, SourceFilePageCount = :pageCount, FileHash = :fileHash WHERE Id = :id');
					$stmtUpdate->execute([
						':id' => $row['Id'],
						':path' => $cleanPath,
						':pageOrder' => $facts->pageOrder,
						':fileSize' => $facts->fileSize,
						':lastModified' => $facts->lastModified,
						':type' => $fileType,
						':pageCount' => $facts->sourceFilePageCount,
						':fileHash' => $facts->fileHash,
					]);
					$updatedExisting = true;
					break;
				}
			}

			if (!$updatedExisting) {
				$existing = $db->prepare('SELECT Id FROM Files WHERE SongId = :songId AND Path = :path');
				$existing->execute([':songId' => $sqliteSongId, ':path' => $cleanPath]);
				if ($existing->fetch() === false) {
					$stmt = $db->prepare('INSERT INTO Files (SongId, Path, PageOrder, FileSize, LastModified, Source, Type, Password, SourceFilePageCount, FileHash, Width, Height) VALUES (:songId, :path, :pageOrder, :fileSize, :lastModified, 0, :type, \'\', :pageCount, :fileHash, 0, 0)');
					$stmt->execute([
						':songId' => $sqliteSongId,
						':path' => $cleanPath,
						':pageOrder' => $facts->pageOrder,
						':fileSize' => $facts->fileSize,
						':lastModified' => $facts->lastModified,
						':type' => $fileType,
						':pageCount' => $facts->sourceFilePageCount,
						':fileHash' => $facts->fileHash,
					]);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not record orphan file in SQLite database: ' . $e->getMessage());
		}
	}

	private function regenerateSongPathsIfEnabled(
		int $libraryId,
		string $userId,
		IUser $user,
		Folder $targetFolder,
		string $databasePath,
		int $songId,
	): void {
		if (!$this->libraryService->isAutoPathsEnabled($userId, $libraryId)) {
			return;
		}
		$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);
		try {
			$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
			$hashCodes?->save();
		} catch (\Throwable $e) {
			$this->logger->debug('Naming rule not applied after linking orphan file: ' . $e->getMessage());
		}
	}
}
