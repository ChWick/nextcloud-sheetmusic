<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class SongService {
	public function __construct(
		private LibraryService $libraryService,
		private SongMapper $songMapper,
		private SongFileService $songFileService,
		private FileManager $fileManager,
		private LoggerInterface $logger,
		private ?LibraryIntegrityService $libraryIntegrityService = null,
	) {
	}

	public function getSongs(IUser $user, int $libraryId): ?array {
		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not load songs from MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'userId' => $user->getUID(),
			]);

			throw $e;
		}
	}

	public function createSong(
		IUser $user,
		int $libraryId,
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
		array $bookmarks = [],
		array $audioFiles = [],
	): ?array {
		$title = trim($title);
		if ($title === '') {
			return null;
		}

		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$songId = $this->songMapper->createSong(
				$databasePath,
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
			);

			if (!empty($bookmarks)) {
				$this->songMapper->updateBookmarks($databasePath, $songId, $bookmarks);
			}
			if (!empty($audioFiles)) {
				$this->songMapper->updateAudioFiles($databasePath, $songId, $audioFiles);
			}

			return ['createdSongId' => $songId] + $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not create song in MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'title' => $title,
			]);

			throw $e;
		}
	}

	public function updateSong(
		IUser $user,
		int $songId,
		int $libraryId,
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
		array $bookmarks = [],
		array $audioFiles = [],
	): ?array {
		if ($title === '') {
			return null;
		}

		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$this->songMapper->updateSong(
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
			);

			$this->songMapper->updateBookmarks($databasePath, $songId, $bookmarks);
			$this->songMapper->updateAudioFiles($databasePath, $songId, $audioFiles);
			$this->songFileService->syncSongFileMetadata($user, $databasePath, $songId, $libraryId);

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not update song in MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songId' => $songId,
			]);

			throw $e;
		}
	}

	public function batchUpdateSongs(
		IUser $user,
		int $libraryId,
		array $songIds,
		?array $composers = null,
		?array $books = null,
		?array $collections = null,
		?array $artists = null,
		?array $genres = null,
		?array $keys = null,
		?array $customGroups = null,
		?array $years = null,
		?array $signatures = null,
		?array $sourceTypes = null,
	): ?array {
		if (empty($songIds)) {
			return null;
		}

		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$this->songMapper->batchUpdateSongs(
				$databasePath,
				$songIds,
				$composers,
				$books,
				$collections,
				$artists,
				$genres,
				$keys,
				$customGroups,
				$years,
				$signatures,
				$sourceTypes,
			);

			foreach ($songIds as $songId) {
				$this->songFileService->syncSongFileMetadata($user, $databasePath, (int)$songId, $libraryId);
			}

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not batch update songs in MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songIds' => $songIds,
			]);

			throw $e;
		}
	}

	public function deleteSong(IUser $user, int $songId, int $libraryId, bool $deleteFiles = true): ?array {
		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$song = $this->songMapper->findSong($databasePath, $songId);
			if ($song === null) {
				return null;
			}

			$filePaths = array_map(
				static fn (MobileSheetsFile $file): string => $file->path,
				$song->files,
			);
			if ($deleteFiles) {
				$hashCodes = $this->songFileService->hashCodesFor($databasePath);
				foreach ($filePaths as $filePath) {
					$storedFile = $this->fileManager->pathOfStoredFile($filePath, $user);
					if ($storedFile !== null) {
						$this->songFileService->deleteFileNode($storedFile->internal);
					}
					$hashCodes?->removePath($filePath);
				}
				$hashCodes?->save();
			}

			$this->songMapper->deleteSong($databasePath, $songId);

			$this->logger->info('Deleted MobileSheets song', [
				'libraryId' => $libraryId,
				'songId' => $songId,
				'title' => $song->title,
				'files' => count($filePaths),
				'filesDeleted' => $deleteFiles,
			]);

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not delete song from MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songId' => $songId,
			]);

			throw $e;
		}
	}

	/**
	 * Merge song $droppedId into song $keepId.
	 *
	 * All entity relations, files, bookmarks, and audio files from $droppedId
	 * are re-pointed to $keepId (with de-duplication for relation tables).
	 * The Songs row for $keepId is then overwritten with the fields supplied
	 * in $merged. The $droppedId Songs row is deleted.
	 *
	 * @param array{
	 *     title: string,
	 *     composerIds?: list<int>,
	 *     bookIds?: list<int>,
	 *     collectionIds?: list<int>,
	 *     artistIds?: list<int>,
	 *     genreIds?: list<int>,
	 *     keyIds?: list<int>,
	 *     customGroupIds?: list<int>,
	 *     yearIds?: list<int>,
	 *     signatureIds?: list<int>,
	 *     sourceTypeIds?: list<int>,
	 *     stars?: int,
	 *     difficulty?: int,
	 *     duration?: int,
	 *     keywords?: string,
	 *     custom?: string,
	 *     custom2?: string,
	 *     sortTitle?: string,
	 * } $merged
	 */
	public function mergeSong(IUser $user, int $libraryId, int $keepId, int $droppedId, array $merged): ?array {
		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			// 1. Apply the merged metadata to the song that is being kept.
			$this->songMapper->updateSong(
				$databasePath,
				$keepId,
				$merged['title'] ?? '',
				array_map('intval', $merged['composerIds'] ?? []),
				array_map('intval', $merged['bookIds'] ?? []),
				array_map('intval', $merged['collectionIds'] ?? []),
				array_map('intval', $merged['artistIds'] ?? []),
				array_map('intval', $merged['genreIds'] ?? []),
				array_map('intval', $merged['keyIds'] ?? []),
				array_map('intval', $merged['customGroupIds'] ?? []),
				array_map('intval', $merged['yearIds'] ?? []),
				array_map('intval', $merged['signatureIds'] ?? []),
				array_map('intval', $merged['sourceTypeIds'] ?? []),
				(int)($merged['stars'] ?? 0),
				(int)($merged['difficulty'] ?? 0),
				(int)($merged['duration'] ?? 0),
				(string)($merged['keywords'] ?? ''),
				(string)($merged['custom'] ?? ''),
				(string)($merged['custom2'] ?? ''),
				(string)($merged['sortTitle'] ?? ''),
			);

			// 2. Move all references (files, relations, …) and delete the dropped song row.
			$this->songMapper->mergeSongs($databasePath, $keepId, $droppedId);

			$this->logger->info('Merged MobileSheets songs', [
				'libraryId' => $libraryId,
				'keepId' => $keepId,
				'droppedId' => $droppedId,
			]);

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not merge songs in MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'keepId' => $keepId,
				'droppedId' => $droppedId,
			]);

			throw $e;
		}
	}

	/**
	 * Resolve conflicts for keeping two separate songs.
	 * Detaches requested files, updates metadata for both songs, and verifies via LibraryIntegrityService
	 * that no duplicate physical paths or naming rule conflicts remain between them.
	 */
	public function resolveBothSongs(IUser $user, int $libraryId, array $songAData, array $songBData): ?array {
		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		$songAId = (int)($songAData['id'] ?? 0);
		$songBId = (int)($songBData['id'] ?? 0);
		if ($songAId <= 0 || $songBId <= 0) {
			return ['error' => 'Invalid song IDs provided for conflict resolution.'];
		}

		try {
			// 1. Detach files requested for Song A
			if (!empty($songAData['detachFileIds']) && is_array($songAData['detachFileIds'])) {
				foreach ($songAData['detachFileIds'] as $fileId) {
					$this->songFileService->deleteSongFile($user, $libraryId, $songAId, (int)$fileId);
				}
			}

			// 2. Detach files requested for Song B
			if (!empty($songBData['detachFileIds']) && is_array($songBData['detachFileIds'])) {
				foreach ($songBData['detachFileIds'] as $fileId) {
					$this->songFileService->deleteSongFile($user, $libraryId, $songBId, (int)$fileId);
				}
			}

			// 3. Update Song A metadata
			$this->songMapper->updateSong(
				$databasePath,
				$songAId,
				(string)($songAData['title'] ?? ''),
				array_map('intval', $songAData['composerIds'] ?? []),
				array_map('intval', $songAData['bookIds'] ?? []),
				array_map('intval', $songAData['collectionIds'] ?? []),
				array_map('intval', $songAData['artistIds'] ?? []),
				array_map('intval', $songAData['genreIds'] ?? []),
				array_map('intval', $songAData['keyIds'] ?? []),
				array_map('intval', $songAData['customGroupIds'] ?? []),
				array_map('intval', $songAData['yearIds'] ?? []),
				array_map('intval', $songAData['signatureIds'] ?? []),
				array_map('intval', $songAData['sourceTypeIds'] ?? []),
				(int)($songAData['stars'] ?? 0),
				(int)($songAData['difficulty'] ?? 0),
				(int)($songAData['duration'] ?? 0),
				(string)($songAData['keywords'] ?? ''),
				(string)($songAData['custom'] ?? ''),
				(string)($songAData['custom2'] ?? ''),
				(string)($songAData['sortTitle'] ?? ''),
			);

			// 4. Update Song B metadata
			$this->songMapper->updateSong(
				$databasePath,
				$songBId,
				(string)($songBData['title'] ?? ''),
				array_map('intval', $songBData['composerIds'] ?? []),
				array_map('intval', $songBData['bookIds'] ?? []),
				array_map('intval', $songBData['collectionIds'] ?? []),
				array_map('intval', $songBData['artistIds'] ?? []),
				array_map('intval', $songBData['genreIds'] ?? []),
				array_map('intval', $songBData['keyIds'] ?? []),
				array_map('intval', $songBData['customGroupIds'] ?? []),
				array_map('intval', $songBData['yearIds'] ?? []),
				array_map('intval', $songBData['signatureIds'] ?? []),
				array_map('intval', $songBData['sourceTypeIds'] ?? []),
				(int)($songBData['stars'] ?? 0),
				(int)($songBData['difficulty'] ?? 0),
				(int)($songBData['duration'] ?? 0),
				(string)($songBData['keywords'] ?? ''),
				(string)($songBData['custom'] ?? ''),
				(string)($songBData['custom2'] ?? ''),
				(string)($songBData['sortTitle'] ?? ''),
			);

			// 5. Regenerate file paths according to naming rules (best effort for remaining files)
			['targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
			$hashCodes = $this->songFileService->hashCodesFor($databasePath);
			try {
				$this->fileManager->regenerateSongFilePaths($songAId, $user, $targetFolder, $databasePath, $hashCodes);
			} catch (\Throwable $e) {
				// Ignore if song A now has 0 files or path cannot be regenerated
			}
			try {
				$this->fileManager->regenerateSongFilePaths($songBId, $user, $targetFolder, $databasePath, $hashCodes);
			} catch (\Throwable $e) {
				// Ignore if song B now has 0 files or path cannot be regenerated
			}
			$hashCodes?->save();

			// 6. Server-side audit check: Verify that no duplicate file path issues remain between song A & song B
			if ($this->libraryIntegrityService !== null) {
				$issues = $this->libraryIntegrityService->checkIntegrity($libraryId, $user->getUID());
				foreach ($issues as $issue) {
					if ($issue->type === \OCA\MobileSheetsManager\Service\IntegrityIssue::TYPE_DUPLICATE_FILE_PATH) {
						$issueSongIds = $issue->details['songIds'] ?? [];
						$this->logger->warning('Integrity audit found TYPE_DUPLICATE_FILE_PATH during resolveBothSongs', [
							'libraryId' => $libraryId,
							'songAId' => $songAId,
							'songBId' => $songBId,
							'issueSongIds' => $issueSongIds,
							'filePath' => $issue->details['path'] ?? null,
						]);
						if (in_array($songAId, $issueSongIds, true) && in_array($songBId, $issueSongIds, true)) {
							$this->logger->error('Conflict cannot be resolved: Song A and Song B still share duplicate file path', [
								'libraryId' => $libraryId,
								'songAId' => $songAId,
								'songBId' => $songBId,
								'issueDetails' => $issue->details,
							]);
							return ['error' => 'Conflict cannot be resolved: Song A and Song B still share the same physical file or path in storage. Adapt titles or detach shared files.'];
						}
					}
				}
			}

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not resolve both songs in MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songAId' => $songAId,
				'songBId' => $songBId,
			]);

			throw $e;
		}
	}
}
