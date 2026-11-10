<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Db\LibraryMapper;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class EntityService {
	public function __construct(
		private LibraryService $libraryService,
		private LoggerInterface $logger,
		private InternalEntityMapper $internalEntityMapper,
		private InternalSongMapper $internalSongMapper,
		private LibraryMapper $libraryMapper,
		private ?SublibraryService $sublibraryService = null,
	) {
	}

	public function createEntity(IUser $user, int $libraryId, string $entityType, string $nameOrTitle): ?array {
		$nameOrTitle = trim($nameOrTitle);
		if ($nameOrTitle === '') {
			return null;
		}

		if (!$this->libraryService->canWriteLibrary($user->getUID(), $libraryId)) {
			return null;
		}

		$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);

		try {
			$normType = InternalEntityMapper::normalizeType($entityType);
			$entity = new InternalEntity(
				id: null,
				uuid: InternalEntity::generateUuid(),
				libraryId: $targetLibraryId,
				type: $normType,
				name: $nameOrTitle,
			);
			$created = $this->internalEntityMapper->insert($entity);
			$this->libraryMapper->touch($targetLibraryId);

			$key = $normType === 'book' ? 'title' : 'name';
			$result = ['id' => $created->id, $key => $nameOrTitle];
			if ($normType === 'year') {
				$result['year'] = (int)$nameOrTitle;
			}
			if ($normType === 'sourcetype') {
				$result['type'] = $nameOrTitle;
			}
			return $result;
		} catch (\Throwable $e) {
			$this->logger->error('Could not create entity in SheetMusic library', [
				'exception' => $e,
				'entityType' => $entityType,
				'nameOrTitle' => $nameOrTitle,
				'libraryId' => $libraryId,
			]);
			throw $e;
		}
	}

	/**
	 * @param 'composers'|'books'|'collections'|'artists'|'genres'|'keys'|'customgroups'|'years'|'signatures'|'sourcetypes'|'instruments'|'arrangers' $entityType
	 */
	public function deleteEntity(IUser $user, int $libraryId, string $entityType, int $id): ?LibraryData {
		if (!$this->libraryService->canWriteLibrary($user->getUID(), $libraryId)) {
			return null;
		}

		$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);

		try {
			if (!$this->internalEntityMapper->delete($id)) {
				return null;
			}

			$this->libraryMapper->touch($targetLibraryId);

			$sublibrary = null;
			if ($libraryId >= LibraryShareService::SUBLIBRARY_ID_OFFSET && $this->sublibraryService !== null) {
				$sublibrary = $this->sublibraryService->getSublibrary($libraryId - LibraryShareService::SUBLIBRARY_ID_OFFSET);
			}

			return $this->internalSongMapper->findLibraryData($targetLibraryId, $user, null, null, $sublibrary);
		} catch (\Throwable $e) {
			$this->logger->error('Could not delete entity from SheetMusic library', [
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
	 * @return array{mergedCount: int, libraryData: LibraryData}|null
	 */
	public function mergeEntities(IUser $user, int $libraryId, string $entityType, int $keepId, array $duplicateIds): ?array {
		if ($keepId <= 0 || $duplicateIds === []) {
			return null;
		}

		if (!$this->libraryService->canWriteLibrary($user->getUID(), $libraryId)) {
			return null;
		}

		$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);

		try {
			$normType = InternalEntityMapper::normalizeType($entityType);
			$merged = $this->internalEntityMapper->mergeEntities($targetLibraryId, $normType, $keepId, $duplicateIds);
			if ($merged <= 0) {
				return null;
			}

			$this->libraryMapper->touch($targetLibraryId);

			$sublibrary = null;
			if ($libraryId >= LibraryShareService::SUBLIBRARY_ID_OFFSET && $this->sublibraryService !== null) {
				$sublibrary = $this->sublibraryService->getSublibrary($libraryId - LibraryShareService::SUBLIBRARY_ID_OFFSET);
			}

			return [
				'mergedCount' => $merged,
				'libraryData' => $this->internalSongMapper->findLibraryData($targetLibraryId, $user, null, null, $sublibrary),
			];
		} catch (\Throwable $e) {
			$this->logger->error('Could not merge entities in SheetMusic library', [
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
