<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Storage\MobileSheetsHashCodesFile;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class EntityService {
	public function __construct(
		private LibraryService $libraryService,
		private SongMapper $songMapper,
		private LoggerInterface $logger,
		private ?FileManager $fileManager = null,
	) {
	}

	public function createEntity(IUser $user, int $libraryId, string $entityType, string $nameOrTitle): ?array {
		$nameOrTitle = trim($nameOrTitle);
		if ($nameOrTitle === '') {
			return null;
		}

		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$id = match ($entityType) {
				'composer' => $this->songMapper->createComposer($databasePath, $nameOrTitle),
				'book' => $this->songMapper->createBook($databasePath, $nameOrTitle),
				'collection' => $this->songMapper->createCollection($databasePath, $nameOrTitle),
				'artist' => $this->songMapper->createArtist($databasePath, $nameOrTitle),
				'genre' => $this->songMapper->createGenre($databasePath, $nameOrTitle),
				'key' => $this->songMapper->createKey($databasePath, $nameOrTitle),
				'customgroup' => $this->songMapper->createCustomGroup($databasePath, $nameOrTitle),
				'year' => $this->songMapper->createYear($databasePath, $nameOrTitle),
				'signature' => $this->songMapper->createSignature($databasePath, $nameOrTitle),
				'sourcetype' => $this->songMapper->createSourceType($databasePath, $nameOrTitle),
				default => null,
			};

			if ($id === null) {
				return null;
			}

			$key = $entityType === 'book' ? 'title' : 'name';
			$result = ['id' => $id, $key => $nameOrTitle];
			if ($entityType === 'year') {
				$result['year'] = (int)$nameOrTitle;
			}
			if ($entityType === 'sourcetype') {
				$result['type'] = $nameOrTitle;
			}
			return $result;
		} catch (\Throwable $e) {
			$this->logger->error('Could not create entity in MobileSheets library', [
				'exception' => $e,
				'entityType' => $entityType,
				'nameOrTitle' => $nameOrTitle,
				'libraryId' => $libraryId,
			]);

			throw $e;
		}
	}

	/**
	 * @param 'composers'|'books'|'collections'|'artists'|'genres'|'keys'|'customgroups'|'years'|'signatures'|'sourcetypes' $entityType
	 */
	public function deleteEntity(IUser $user, int $libraryId, string $entityType, int $id): ?array {
		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			if (!$this->songMapper->deleteEntity($databasePath, $entityType, $id)) {
				return null;
			}

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not delete entity from MobileSheets library', [
				'exception' => $e,
				'entityType' => $entityType,
				'entityId' => $id,
				'libraryId' => $libraryId,
			]);

			throw $e;
		}
	}

	/**
	 * Merge duplicate entity records into a canonical record and re-link attached songs.
	 *
	 * @param list<int> $duplicateIds
	 * @return array{mergedCount: int, libraryData: array}|null
	 */
	public function mergeEntities(IUser $user, int $libraryId, string $entityType, int $keepId, array $duplicateIds): ?array {
		if ($keepId <= 0 || $duplicateIds === []) {
			return null;
		}

		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			// 1. Identify affected songs before merging for potential path regeneration
			$affectedSongIds = $this->songMapper->getSongIdsForEntity($databasePath, $entityType, $duplicateIds);

			// 2. Perform merge in database
			$merged = $this->songMapper->mergeDuplicateEntities($databasePath, $entityType, $keepId, $duplicateIds);
			if ($merged <= 0) {
				return null;
			}

			// 3. Regenerate file paths if autoPaths is enabled and songs were affected
			if (
				$this->fileManager !== null
				&& $this->libraryService->isAutoPathsEnabled($user->getUID(), $libraryId)
				&& $affectedSongIds !== []
			) {
				try {
					['targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
					$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);
					foreach ($affectedSongIds as $songId) {
						try {
							$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
						} catch (\Throwable $e) {
							// Best-effort: keep processing other songs
						}
					}
					$hashCodes?->save();
				} catch (\Throwable $e) {
					$this->logger->warning('Could not regenerate file paths after merging entities', [
						'exception' => $e,
						'libraryId' => $libraryId,
						'entityType' => $entityType,
						'keepId' => $keepId,
					]);
				}
			}

			return [
				'mergedCount' => $merged,
				'libraryData' => $this->songMapper->findLibraryData($databasePath),
			];
		} catch (\Throwable $e) {
			$this->logger->error('Could not merge entities in MobileSheets library', [
				'exception' => $e,
				'entityType' => $entityType,
				'keepId' => $keepId,
				'duplicateIds' => $duplicateIds,
				'libraryId' => $libraryId,
			]);

			throw $e;
		}
	}
}

