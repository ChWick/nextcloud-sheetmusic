<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Db\LibraryMapper;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class SongService {
	public function __construct(
		private LibraryService $libraryService,
		private SongFileService $songFileService,
		private FileManager $fileManager,
		private LoggerInterface $logger,
		private InternalSongMapper $internalSongMapper,
		private InternalEntityMapper $internalEntityMapper,
		private InternalSongFileMapper $internalSongFileMapper,
		private LibraryMapper $libraryMapper,
		private IRootFolder $rootFolder,
		private ?LibraryIntegrityService $libraryIntegrityService = null,
	) {
	}

	public function getSongs(IUser $user, int $libraryId): ?LibraryData {
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
	): ?LibraryData {
		$title = trim($title);
		if ($title === '') {
			return null;
		}

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

			$this->setSongEntities($songId, [
				'composer' => $composerIds,
				'book' => $bookIds,
				'collection' => $collectionIds,
				'artist' => $artistIds,
				'genre' => $genreIds,
				'key' => $keyIds,
				'customgroup' => $customGroupIds,
				'year' => $yearIds,
				'signature' => $signatureIds,
				'sourcetype' => $sourceTypeIds,
				'instrument' => $instrumentIds,
			]);

			$this->libraryMapper->touch($libraryId);

			$data = $this->internalSongMapper->findLibraryData($libraryId, $user);
			$data->createdSongId = $songId;

			return $data;
		} catch (\Throwable $e) {
			$this->logger->error('Could not create song in SheetMusic library', [
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
	): ?LibraryData {
		if ($title === '') {
			return null;
		}

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
				$song->updatedAt = time();
				$this->internalSongMapper->update($song);

				$this->setSongEntities($songId, [
					'composer' => $composerIds,
					'book' => $bookIds,
					'collection' => $collectionIds,
					'artist' => $artistIds,
					'genre' => $genreIds,
					'key' => $keyIds,
					'customgroup' => $customGroupIds,
					'year' => $yearIds,
					'signature' => $signatureIds,
					'sourcetype' => $sourceTypeIds,
					'instrument' => $instrumentIds,
				]);

				$this->libraryMapper->touch($libraryId);

				$this->songFileService->syncSongFileMetadata($user, '', $songId, $libraryId);

				return $this->internalSongMapper->findLibraryData($libraryId, $user);
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->error('Could not update song in SheetMusic library', [
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
	): ?LibraryData {
		if (empty($songIds)) {
			return null;
		}

		try {
			foreach ($songIds as $songId) {
				$sid = (int)$songId;
				$this->setSongEntities($sid, [
					'composer' => $composers,
					'book' => $books,
					'collection' => $collections,
					'artist' => $artists,
					'genre' => $genres,
					'key' => $keys,
					'customgroup' => $customGroups,
					'year' => $years,
					'signature' => $signatures,
					'sourcetype' => $sourceTypes,
					'instrument' => $instruments,
				]);
				$song = $this->internalSongMapper->findById($sid);
				if ($song !== null) {
					$song->updatedAt = time();
					$this->internalSongMapper->update($song);
				}
				$this->songFileService->syncSongFileMetadata($user, '', $sid, $libraryId);
			}
			$this->libraryMapper->touch($libraryId);

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

	public function deleteSong(IUser $user, int $songId, int $libraryId, bool $deleteFiles = true): ?LibraryData {
		try {
			$files = $this->internalSongFileMapper->findAllForSong($songId);
			if ($deleteFiles) {
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
			$this->internalSongMapper->delete($songId);
			$this->libraryMapper->touch($libraryId);

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

	/**
	 * @param list<int> $songIds
	 * @return array<string, mixed>|null
	 */
	public function batchDeleteSongs(IUser $user, int $libraryId, array $songIds, bool $deleteFiles = true): ?LibraryData {
		if ($songIds === []) {
			return $this->internalSongMapper->findLibraryData($libraryId, $user);
		}

		try {
			if ($deleteFiles) {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				foreach ($songIds as $songId) {
					$songId = (int)$songId;
					$files = $this->internalSongFileMapper->findAllForSong($songId);
					foreach ($files as $file) {
						try {
							$nodes = $userFolder->getById($file->fileId);
							if (!empty($nodes)) {
								$nodes[0]->delete();
							}
						} catch (\Throwable) {
						}
					}
				}
			}
			foreach ($songIds as $songId) {
				$songId = (int)$songId;
				$this->internalSongFileMapper->deleteAllForSong($songId);
				$this->internalSongMapper->delete($songId);
			}
			$this->libraryMapper->touch($libraryId);

			return $this->internalSongMapper->findLibraryData($libraryId, $user);
		} catch (\Throwable $e) {
			$this->logger->error('Could not batch delete songs from SheetMusic library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songIds' => $songIds,
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
	public function mergeSong(IUser $user, int $libraryId, int $keepId, int $droppedId, array $merged): ?LibraryData {
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
			$this->libraryMapper->touch($libraryId);

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

	/**
	 * Resolve conflicts for keeping two separate songs.
	 * Detaches requested files, updates metadata for both songs, and verifies via LibraryIntegrityService
	 * that no duplicate physical paths or naming rule conflicts remain between them.
	 */
	public function resolveBothSongs(IUser $user, int $libraryId, array $songAData, array $songBData): LibraryData|array|null {
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

			// 5. Server-side audit check: Verify that no duplicate file path issues remain between song A & song B
			if ($this->libraryIntegrityService !== null) {
				$issues = $this->libraryIntegrityService->checkIntegrity($libraryId, $user->getUID());
				foreach ($issues as $issue) {
					if ($issue->type === IntegrityIssue::TYPE_DUPLICATE_FILE_PATH) {
						$issueSongIds = $issue->details['songIds'] ?? [];
						if (in_array($songAId, $issueSongIds, true) && in_array($songBId, $issueSongIds, true)) {
							return ['error' => 'Conflict cannot be resolved: Song A and Song B still share the same physical file or path in storage. Adapt titles or detach shared files.'];
						}
					}
				}
			}

			return $this->getSongs($user, $libraryId);
		} catch (\Throwable $e) {
			$this->logger->error('Could not resolve both songs in SheetMusic library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songAId' => $songAId,
				'songBId' => $songBId,
			]);

			throw $e;
		}
	}

	/**
	 * Associate a song with multiple entity types. Skips null entries.
	 * Supports both a plain list of IDs or an update spec with action ('add', 'remove', 'replace') and ids.
	 *
	 * @param array<string, array<int|string>|array{action: string, ids: array<int|string>}|null> $entitiesByType
	 */
	private function setSongEntities(int $songId, array $entitiesByType): void {
		foreach ($entitiesByType as $type => $spec) {
			if ($spec === null) {
				continue;
			}
			$typeStr = (string)$type;
			if (is_array($spec) && isset($spec['action']) && isset($spec['ids']) && is_array($spec['ids'])) {
				$action = (string)$spec['action'];
				$targetIds = array_map('intval', $spec['ids']);
				if ($action === 'replace') {
					$this->internalEntityMapper->setSongEntitiesForType($songId, $typeStr, $targetIds);
				} elseif ($action === 'add') {
					$currentIds = $this->internalEntityMapper->findEntityIdsForSong($songId, $typeStr);
					$newIds = array_values(array_unique(array_merge($currentIds, $targetIds)));
					$this->internalEntityMapper->setSongEntitiesForType($songId, $typeStr, $newIds);
				} elseif ($action === 'remove') {
					$currentIds = $this->internalEntityMapper->findEntityIdsForSong($songId, $typeStr);
					$newIds = array_values(array_diff($currentIds, $targetIds));
					$this->internalEntityMapper->setSongEntitiesForType($songId, $typeStr, $newIds);
				}
			} else {
				$this->internalEntityMapper->setSongEntitiesForType($songId, $typeStr, array_map('intval', (array)$spec));
			}
		}
	}
}
