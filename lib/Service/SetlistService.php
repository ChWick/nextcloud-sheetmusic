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
	) {
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	public function getSetlists(IUser $user, int $libraryId): ?array {
		$library = $this->libraryMapper->findForUser($user->getUID(), $libraryId);
		if ($library === null) {
			return null;
		}

		$setlists = $this->internalEntityMapper->findAllForLibrary($libraryId, 'setlist', includeDeleted: false);
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
	public function getSetlist(IUser $user, int $libraryId, int $id): ?array {
		$library = $this->libraryMapper->findForUser($user->getUID(), $libraryId);
		if ($library === null) {
			return null;
		}

		$setlist = $this->internalEntityMapper->findById($id);
		if ($setlist === null || $setlist->libraryId !== $libraryId || $setlist->type !== 'setlist' || $setlist->deletedAt !== null) {
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
		if ($name === '') {
			return null;
		}

		$library = $this->libraryMapper->findForUser($user->getUID(), $libraryId);
		if ($library === null) {
			return null;
		}

		$now = time();
		$setlist = new InternalEntity(
			id: null,
			uuid: InternalEntity::generateUuid(),
			libraryId: $libraryId,
			type: 'setlist',
			name: $name,
			sortBy: SongSortingUtil::MODE_MANUAL,
			ascending: 1,
			createdAt: $now,
			updatedAt: $now,
		);

		$created = $this->internalEntityMapper->insert($setlist);
		$this->libraryMapper->touch($libraryId);

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
		$library = $this->libraryMapper->findForUser($user->getUID(), $libraryId);
		if ($library === null) {
			return null;
		}

		$setlist = $this->internalEntityMapper->findById($id);
		if ($setlist === null || $setlist->libraryId !== $libraryId || $setlist->type !== 'setlist' || $setlist->deletedAt !== null) {
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
			$setlist->updatedAt = time();
			$this->internalEntityMapper->update($setlist);
			$this->libraryMapper->touch($libraryId);
		}

		return $setlist;
	}

	public function deleteSetlist(IUser $user, int $libraryId, int $id): bool {
		$library = $this->libraryMapper->findForUser($user->getUID(), $libraryId);
		if ($library === null) {
			return false;
		}

		$setlist = $this->internalEntityMapper->findById($id);
		if ($setlist === null || $setlist->libraryId !== $libraryId || $setlist->type !== 'setlist') {
			return false;
		}

		$this->internalSetlistItemMapper->deleteForSetlist($id);
		$this->internalEntityMapper->delete($id);
		$this->libraryMapper->touch($libraryId);

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
		$library = $this->libraryMapper->findForUser($user->getUID(), $libraryId);
		if ($library === null) {
			return null;
		}

		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist === null || $setlist->libraryId !== $libraryId || $setlist->type !== 'setlist' || $setlist->deletedAt !== null) {
			return null;
		}

		$song = $this->internalSongMapper->findById($songId);
		if ($song === null || $song->libraryId !== $libraryId || $song->deletedAt !== null) {
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
		$this->libraryMapper->touch($libraryId);

		return $this->internalSetlistItemMapper->findById((int)$inserted->id);
	}

	public function removeItem(IUser $user, int $libraryId, int $setlistId, int $itemId): bool {
		$library = $this->libraryMapper->findForUser($user->getUID(), $libraryId);
		if ($library === null) {
			return false;
		}

		$item = $this->internalSetlistItemMapper->findById($itemId);
		if ($item === null || $item->setlistId !== $setlistId) {
			return false;
		}

		$this->internalSetlistItemMapper->delete($itemId);

		$now = time();
		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist !== null) {
			$setlist->updatedAt = $now;
			$this->internalEntityMapper->update($setlist);
		}
		$this->libraryMapper->touch($libraryId);

		return true;
	}

	/**
	 * @param list<int> $itemIds
	 */
	public function reorderItems(IUser $user, int $libraryId, int $setlistId, array $itemIds): bool {
		$library = $this->libraryMapper->findForUser($user->getUID(), $libraryId);
		if ($library === null) {
			return false;
		}

		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist === null || $setlist->libraryId !== $libraryId || $setlist->type !== 'setlist') {
			return false;
		}

		$this->internalSetlistItemMapper->reorder($setlistId, $itemIds);

		$now = time();
		$setlist->updatedAt = $now;
		$this->internalEntityMapper->update($setlist);
		$this->libraryMapper->touch($libraryId);

		return true;
	}
}
