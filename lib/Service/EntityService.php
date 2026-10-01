<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class EntityService {
	public function __construct(
		private LibraryService $libraryService,
		private SongMapper $songMapper,
		private LoggerInterface $logger,
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
				default => null,
			};

			if ($id === null) {
				return null;
			}

			$key = $entityType === 'book' ? 'title' : 'name';
			return ['id' => $id, $key => $nameOrTitle];
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
	 * @param 'composers'|'books'|'collections'|'artists'|'genres'|'keys' $entityType
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
}

