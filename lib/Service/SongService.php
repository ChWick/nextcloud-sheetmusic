<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Db\MobileSheetsFile;
use OCA\SheetMusic\Db\SongMapper;
use OCP\Files\IRootFolder;
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
		private ?InternalSongMapper $internalSongMapper = null,
		private ?InternalEntityMapper $internalEntityMapper = null,
		private ?InternalSongFileMapper $internalSongFileMapper = null,
		private ?LibraryMapper $libraryMapper = null,
		private ?IRootFolder $rootFolder = null,
	) {
	}

	public function getSongs(IUser $user, int $libraryId): ?array {
		if ($this->internalSongMapper !== null) {
			try {
				return $this->internalSongMapper->findLibraryData($libraryId, $user);
			} catch (\Throwable $e) {
				$this->logger->error('Could not load songs from SheetMusic library', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'userId' => $user->getUID(),
				]);

				throw $e;
			}
		}

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
		string $opus = '',
		string $catalogNumber = '',
		string $songNumber = '',
		array $instrumentIds = [],
	): ?array {
		$title = trim($title);
		if ($title === '') {
			return null;
		}

		if ($this->internalSongMapper !== null) {
			try {
				$song = new InternalSong(
					id: null,
					uuid: InternalEntity::generateUuid(),
					libraryId: $libraryId,
					title: $title,
					sortTitle: $sortTitle !== '' ? $sortTitle : null,
					rating: $stars,
					difficulty: $difficulty,
					duration: $duration,
					keywords: $keywords !== '' ? $keywords : null,
					notes: $custom !== '' ? $custom : null,
					opus: $opus !== '' ? $opus : null,
					catalogNumber: $catalogNumber !== '' ? $catalogNumber : null,
					songNumber: $songNumber !== '' ? $songNumber : null,
				);
				$createdSong = $this->internalSongMapper->insert($song);
				$songId = (int)$createdSong->id;

				if ($this->internalEntityMapper !== null) {
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'composer', array_map('intval', $composerIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'book', array_map('intval', $bookIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'collection', array_map('intval', $collectionIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'artist', array_map('intval', $artistIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'genre', array_map('intval', $genreIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'key', array_map('intval', $keyIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'customgroup', array_map('intval', $customGroupIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'year', array_map('intval', $yearIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'signature', array_map('intval', $signatureIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'sourcetype', array_map('intval', $sourceTypeIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, 'instrument', array_map('intval', $instrumentIds));
				}

				$this->libraryMapper?->touch($libraryId);

				return ['createdSongId' => $songId] + $this->internalSongMapper->findLibraryData($libraryId, $user);
			} catch (\Throwable $e) {
				$this->logger->error('Could not create song in SheetMusic library', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'title' => $title,
				]);
				throw $e;
			}
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
				$opus,
				$catalogNumber,
				$songNumber,
				$instrumentIds,
			);

			if (!empty($bookmarks)) {
				$this->songMapper->updateBookmarks($databasePath, $songId, $bookmarks);
			}
			if (!empty($audioFiles)) {
				$this->songMapper->updateAudioFiles($databasePath, $songId, $audioFiles);
			}

			$this->libraryService->syncFromDatabase($user->getUID(), $libraryId);

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
		string $opus = '',
		string $catalogNumber = '',
		string $songNumber = '',
		array $instrumentIds = [],
	): ?array {
		if ($title === '') {
			return null;
		}

		if ($this->internalSongMapper !== null) {
			try {
				$song = $this->internalSongMapper->findById($songId);
				if ($song !== null) {
					$song->title = $title;
					$song->sortTitle = $sortTitle !== '' ? $sortTitle : null;
					$song->rating = $stars;
					$song->difficulty = $difficulty;
					$song->duration = $duration;
					$song->keywords = $keywords !== '' ? $keywords : null;
					$song->notes = $custom !== '' ? $custom : null;
					$song->opus = $opus !== '' ? $opus : null;
					$song->catalogNumber = $catalogNumber !== '' ? $catalogNumber : null;
					$song->songNumber = $songNumber !== '' ? $songNumber : null;
					$this->internalSongMapper->update($song);

					if ($this->internalEntityMapper !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'composer', array_map('intval', $composerIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'book', array_map('intval', $bookIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'collection', array_map('intval', $collectionIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'artist', array_map('intval', $artistIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'genre', array_map('intval', $genreIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'key', array_map('intval', $keyIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'customgroup', array_map('intval', $customGroupIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'year', array_map('intval', $yearIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'signature', array_map('intval', $signatureIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'sourcetype', array_map('intval', $sourceTypeIds));
						$this->internalEntityMapper->setSongEntitiesForType($songId, 'instrument', array_map('intval', $instrumentIds));
					}

					$this->libraryMapper?->touch($libraryId);

					return $this->internalSongMapper->findLibraryData($libraryId, $user);
				}
			} catch (\Throwable $e) {
				$this->logger->error('Could not update song in SheetMusic library', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'songId' => $songId,
				]);
				throw $e;
			}
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
				$opus,
				$catalogNumber,
				$songNumber,
				$instrumentIds,
			);

			$this->songMapper->updateBookmarks($databasePath, $songId, $bookmarks);
			$this->songMapper->updateAudioFiles($databasePath, $songId, $audioFiles);
			$this->songFileService->syncSongFileMetadata($user, $databasePath, $songId, $libraryId);

			$this->libraryService->syncFromDatabase($user->getUID(), $libraryId);

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
		?array $instruments = null,
	): ?array {
		if (empty($songIds)) {
			return null;
		}

		if ($this->internalSongMapper !== null && $this->internalEntityMapper !== null) {
			try {
				foreach ($songIds as $songId) {
					$sid = (int)$songId;
					if ($composers !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'composer', array_map('intval', $composers));
					}
					if ($books !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'book', array_map('intval', $books));
					}
					if ($collections !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'collection', array_map('intval', $collections));
					}
					if ($artists !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'artist', array_map('intval', $artists));
					}
					if ($genres !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'genre', array_map('intval', $genres));
					}
					if ($keys !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'key', array_map('intval', $keys));
					}
					if ($customGroups !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'customgroup', array_map('intval', $customGroups));
					}
					if ($years !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'year', array_map('intval', $years));
					}
					if ($signatures !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'signature', array_map('intval', $signatures));
					}
					if ($sourceTypes !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'sourcetype', array_map('intval', $sourceTypes));
					}
					if ($instruments !== null) {
						$this->internalEntityMapper->setSongEntitiesForType($sid, 'instrument', array_map('intval', $instruments));
					}
				}
				$this->libraryMapper?->touch($libraryId);

				return $this->internalSongMapper->findLibraryData($libraryId, $user);
			} catch (\Throwable $e) {
				$this->logger->error('Could not batch update songs in SheetMusic library', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'songIds' => $songIds,
				]);
				throw $e;
			}
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
				$instruments,
			);

			foreach ($songIds as $songId) {
				$this->songFileService->syncSongFileMetadata($user, $databasePath, (int)$songId, $libraryId);
			}

			$this->libraryService->syncFromDatabase($user->getUID(), $libraryId);

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
		if ($this->internalSongMapper !== null) {
			try {
				if ($this->internalSongFileMapper !== null) {
					$files = $this->internalSongFileMapper->findAllForSong($songId);
					if ($deleteFiles && $this->rootFolder !== null) {
						foreach ($files as $file) {
							try {
								$nodes = $this->rootFolder->getUserFolder($user->getUID())->getById($file->fileId);
								if (!empty($nodes)) {
									$nodes[0]->delete();
								}
							} catch (\Throwable) {
							}
						}
					}
					$this->internalSongFileMapper->deleteAllForSong($songId);
				}

				$this->internalSongMapper->delete($songId);
				$this->libraryMapper?->touch($libraryId);

				return $this->internalSongMapper->findLibraryData($libraryId, $user);
			} catch (\Throwable $e) {
				$this->logger->error('Could not delete song from SheetMusic library', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'songId' => $songId,
				]);

				throw $e;
			}
		}

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

			$this->libraryService->syncFromDatabase($user->getUID(), $libraryId);

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
		if ($this->internalSongMapper !== null) {
			try {
				if (!empty($merged['title'])) {
					$this->updateSong(
						user: $user,
						songId: $keepId,
						libraryId: $libraryId,
						title: $merged['title'],
						composerIds: $merged['composerIds'] ?? [],
						bookIds: $merged['bookIds'] ?? [],
						collectionIds: $merged['collectionIds'] ?? [],
						artistIds: $merged['artistIds'] ?? [],
						genreIds: $merged['genreIds'] ?? [],
						keyIds: $merged['keyIds'] ?? [],
						customGroupIds: $merged['customGroupIds'] ?? [],
						yearIds: $merged['yearIds'] ?? [],
						signatureIds: $merged['signatureIds'] ?? [],
						sourceTypeIds: $merged['sourceTypeIds'] ?? [],
						stars: (int)($merged['stars'] ?? 0),
						difficulty: (int)($merged['difficulty'] ?? 0),
						duration: (int)($merged['duration'] ?? 0),
						keywords: (string)($merged['keywords'] ?? ''),
						custom: (string)($merged['custom'] ?? ''),
						custom2: (string)($merged['custom2'] ?? ''),
						sortTitle: (string)($merged['sortTitle'] ?? ''),
					);
				}
				$this->internalSongMapper->mergeSongs($libraryId, $keepId, $droppedId);
				$this->libraryMapper?->touch($libraryId);

				return $this->internalSongMapper->findLibraryData($libraryId, $user);
			} catch (\Throwable $e) {
				$this->logger->error('Could not merge songs in SheetMusic library', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'keepId' => $keepId,
					'droppedId' => $droppedId,
				]);

				throw $e;
			}
		}

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
			$this->updateSong(
				$user,
				$songAId,
				$libraryId,
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
				$songAData['bookmarks'] ?? [],
				$songAData['audioFiles'] ?? [],
				(string)($songAData['opus'] ?? ''),
				(string)($songAData['catalogNumber'] ?? ''),
				(string)($songAData['songNumber'] ?? ''),
				array_map('intval', $songAData['instrumentIds'] ?? []),
			);

			// 4. Update Song B metadata
			$this->updateSong(
				$user,
				$songBId,
				$libraryId,
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
				$songBData['bookmarks'] ?? [],
				$songBData['audioFiles'] ?? [],
				(string)($songBData['opus'] ?? ''),
				(string)($songBData['catalogNumber'] ?? ''),
				(string)($songBData['songNumber'] ?? ''),
				array_map('intval', $songBData['instrumentIds'] ?? []),
			);

			// 5. Regenerate file paths according to naming rules (best effort for remaining files)
			if ($databasePath !== null) {
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
			}

			// 6. Server-side audit check: Verify that no duplicate file path issues remain between song A & song B
			if ($this->libraryIntegrityService !== null) {
				$issues = $this->libraryIntegrityService->checkIntegrity($libraryId, $user->getUID());
				foreach ($issues as $issue) {
					if ($issue->type === \OCA\SheetMusic\Service\IntegrityIssue::TYPE_DUPLICATE_FILE_PATH) {
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

			return $this->getSongs($user, $libraryId);
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
