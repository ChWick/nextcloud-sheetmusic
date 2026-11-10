<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSetlistItem;
use OCA\SheetMusic\Db\InternalSetlistItemMapper;
use OCA\SheetMusic\Db\InternalSongBookmarkMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Util\SongSortingUtil;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class SetlistService {
	public function __construct(
		private LibraryMapper $libraryMapper,
		private InternalEntityMapper $internalEntityMapper,
		private InternalSetlistItemMapper $internalSetlistItemMapper,
		private InternalSongMapper $internalSongMapper,
		private InternalSongBookmarkMapper $internalSongBookmarkMapper,
		private LoggerInterface $logger,
		private ?LibraryService $libraryService = null,
	) {
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	public function getSetlists(IUser $user, int $libraryId): ?array {
		if ($this->findUserLibrary($user, $libraryId) === null) {
			return null;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlists = $this->internalEntityMapper->findAllForLibrary($targetLibraryId, 'setlist', includeDeleted: false);
		if (empty($setlists)) {
			return [];
		}

		$setlistIds = array_map(fn ($s) => (int)$s->id, $setlists);
		$counts = $this->internalSetlistItemMapper->getItemCountForSetlists($setlistIds);

		$result = [];
		foreach ($setlists as $s) {
			$id = (int)$s->id;
			$result[] = [
				'id' => $id,
				'uuid' => $s->uuid,
				'name' => $s->name,
				'sortBy' => $s->sortBy,
				'ascending' => $s->ascending,
				'itemCount' => $counts[$id] ?? 0,
				'createdAt' => $s->createdAt,
				'updatedAt' => $s->updatedAt,
			];
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getSetlistsForPublicToken(int $libraryId): array {
		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlists = $this->internalEntityMapper->findAllForLibrary($targetLibraryId, 'setlist', includeDeleted: false);
		if (empty($setlists)) {
			return [];
		}

		$setlistIds = array_map(fn ($s) => (int)$s->id, $setlists);
		$counts = $this->internalSetlistItemMapper->getItemCountForSetlists($setlistIds);

		$result = [];
		foreach ($setlists as $s) {
			$id = (int)$s->id;
			$result[] = [
				'id' => $id,
				'uuid' => $s->uuid,
				'name' => $s->name,
				'sortBy' => $s->sortBy,
				'ascending' => $s->ascending,
				'itemCount' => $counts[$id] ?? 0,
				'createdAt' => $s->createdAt,
				'updatedAt' => $s->updatedAt,
			];
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getSetlistForPublicToken(int $libraryId, int $id): ?array {
		$setlist = $this->findActiveSetlist($libraryId, $id);
		if ($setlist === null) {
			return null;
		}

		$items = $this->internalSetlistItemMapper->findForSetlist($id);

		return [
			'id' => $setlist->id,
			'uuid' => $setlist->uuid,
			'name' => $setlist->name,
			'sortBy' => $setlist->sortBy,
			'ascending' => $setlist->ascending,
			'items' => $items,
			'createdAt' => $setlist->createdAt,
			'updatedAt' => $setlist->updatedAt,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getSetlist(IUser $user, int $libraryId, int $id): ?array {
		if ($this->findUserLibrary($user, $libraryId) === null) {
			return null;
		}

		$setlist = $this->findActiveSetlist($libraryId, $id);
		if ($setlist === null) {
			return null;
		}

		$items = $this->internalSetlistItemMapper->findForSetlist($id);

		return [
			'id' => $setlist->id,
			'uuid' => $setlist->uuid,
			'name' => $setlist->name,
			'sortBy' => $setlist->sortBy,
			'ascending' => $setlist->ascending,
			'items' => $items,
			'createdAt' => $setlist->createdAt,
			'updatedAt' => $setlist->updatedAt,
		];
	}

	public function createSetlist(IUser $user, int $libraryId, string $name): ?InternalEntity {
		$name = trim($name);
		if ($name === '' || $this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$now = time();
		$setlist = new InternalEntity(
			id: null,
			uuid: InternalEntity::generateUuid(),
			libraryId: $targetLibraryId,
			type: 'setlist',
			name: $name,
			sortBy: SongSortingUtil::MODE_MANUAL,
			ascending: 1,
			createdAt: $now,
			updatedAt: $now,
		);

		$created = $this->internalEntityMapper->insert($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return $created;
	}

	public function updateSetlist(
		IUser $user,
		int $libraryId,
		int $id,
		?string $name = null,
		?int $sortBy = null,
		?int $ascending = null,
	): ?InternalEntity {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}

		$setlist = $this->findActiveSetlist($libraryId, $id);
		if ($setlist === null) {
			return null;
		}

		$changed = false;
		if ($name !== null) {
			$name = trim($name);
			if ($name !== '' && $name !== $setlist->name) {
				$setlist->name = $name;
				$changed = true;
			}
		}

		if ($sortBy !== null && $sortBy !== $setlist->sortBy) {
			$setlist->sortBy = $sortBy;
			$changed = true;
		}

		if ($ascending !== null && $ascending !== $setlist->ascending) {
			$setlist->ascending = $ascending;
			$changed = true;
		}

		if ($changed) {
			$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
			$setlist->updatedAt = time();
			$this->internalEntityMapper->update($setlist);
			$this->libraryMapper->touch($targetLibraryId);
		}

		return $setlist;
	}

	public function deleteSetlist(IUser $user, int $libraryId, int $id): bool {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return false;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlist = $this->internalEntityMapper->findById($id);
		if ($setlist === null || $setlist->libraryId !== $targetLibraryId || $setlist->type !== 'setlist') {
			return false;
		}

		$this->internalSetlistItemMapper->deleteForSetlist($id);
		$this->internalEntityMapper->delete($id);
		$this->libraryMapper->touch($targetLibraryId);

		return true;
	}

	public function addItem(
		IUser $user,
		int $libraryId,
		int $setlistId,
		int $songId,
		?int $bookmarkId = null,
		?int $position = null,
	): ?InternalSetlistItem {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}

		$setlist = $this->findActiveSetlist($libraryId, $setlistId);
		if ($setlist === null) {
			return null;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$song = $this->internalSongMapper->findById($songId);
		if ($song === null || $song->libraryId !== $targetLibraryId || $song->deletedAt !== null) {
			return null;
		}

		if ($bookmarkId !== null) {
			$bm = $this->internalSongBookmarkMapper->findById($bookmarkId);
			if ($bm === null || $bm->songId !== $songId) {
				return null;
			}
		}

		if ($position === null) {
			$position = $this->internalSetlistItemMapper->getNextPosition($setlistId);
		}

		$now = time();
		$item = new InternalSetlistItem(
			id: null,
			setlistId: $setlistId,
			songId: $songId,
			bookmarkId: $bookmarkId,
			position: $position,
			createdAt: $now,
			updatedAt: $now,
		);

		$inserted = $this->internalSetlistItemMapper->insert($item);

		// Touch setlist and library
		$setlist->updatedAt = $now;
		$this->internalEntityMapper->update($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return $this->internalSetlistItemMapper->findById((int)$inserted->id);
	}

	public function updateItem(
		IUser $user,
		int $libraryId,
		int $setlistId,
		int $itemId,
		?int $bookmarkId = null,
		bool $hasBookmarkParam = true,
	): ?InternalSetlistItem {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}

		$setlist = $this->findActiveSetlist($libraryId, $setlistId);
		if ($setlist === null) {
			return null;
		}

		$item = $this->internalSetlistItemMapper->findById($itemId);
		if ($item === null || $item->setlistId !== $setlistId) {
			return null;
		}

		$changed = false;
		if ($hasBookmarkParam) {
			if ($bookmarkId !== null) {
				$bm = $this->internalSongBookmarkMapper->findById($bookmarkId);
				if ($bm === null || $bm->songId !== $item->songId) {
					return null;
				}
			}
			if ($item->bookmarkId !== $bookmarkId) {
				$item->bookmarkId = $bookmarkId;
				$changed = true;
			}
		}

		if ($changed) {
			$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
			$now = time();
			$item->updatedAt = $now;
			$this->internalSetlistItemMapper->update($item);

			// Touch setlist and library
			$setlist->updatedAt = $now;
			$this->internalEntityMapper->update($setlist);
			$this->libraryMapper->touch($targetLibraryId);
		}

		return $this->internalSetlistItemMapper->findById($itemId);
	}

	public function removeItem(IUser $user, int $libraryId, int $setlistId, int $itemId): bool {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return false;
		}

		$item = $this->internalSetlistItemMapper->findById($itemId);
		if ($item === null || $item->setlistId !== $setlistId) {
			return false;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$this->internalSetlistItemMapper->delete($itemId);

		$now = time();
		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist !== null) {
			$setlist->updatedAt = $now;
			$this->internalEntityMapper->update($setlist);
		}
		$this->libraryMapper->touch($targetLibraryId);

		return true;
	}

	/**
	 * @param list<int> $itemIds
	 */
	public function reorderItems(IUser $user, int $libraryId, int $setlistId, array $itemIds): bool {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return false;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist === null || $setlist->libraryId !== $targetLibraryId || $setlist->type !== 'setlist') {
			return false;
		}

		$this->internalSetlistItemMapper->reorder($setlistId, $itemIds);

		$now = time();
		$setlist->updatedAt = $now;
		$this->internalEntityMapper->update($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return true;
	}

	private function resolveTargetLibraryId(int $libraryId): int {
		if ($this->libraryService !== null) {
			return $this->libraryService->getTargetLibraryId($libraryId);
		}
		return $libraryId;
	}

	private function findUserLibrary(IUser $user, int $libraryId): ?\OCA\SheetMusic\Db\InternalLibrary {
		if ($this->libraryService !== null) {
			return $this->libraryService->getAccessibleLibrary($user->getUID(), $libraryId);
		}
		return $this->libraryMapper->findForUser($user->getUID(), $libraryId);
	}

	private function findUserWritableLibrary(IUser $user, int $libraryId): ?\OCA\SheetMusic\Db\InternalLibrary {
		if ($this->libraryService !== null) {
			if (!$this->libraryService->canWriteLibrary($user->getUID(), $libraryId)) {
				return null;
			}
			return $this->libraryService->getAccessibleLibrary($user->getUID(), $libraryId);
		}
		return $this->libraryMapper->findForUser($user->getUID(), $libraryId);
	}

	private function findActiveSetlist(int $libraryId, int $setlistId): ?InternalEntity {
		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist === null || $setlist->libraryId !== $targetLibraryId || $setlist->type !== 'setlist' || $setlist->deletedAt !== null) {
			return null;
		}
		return $setlist;
	}
}
