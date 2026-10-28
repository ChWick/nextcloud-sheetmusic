<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongHistory;
use OCA\SheetMusic\Db\InternalSongHistoryMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\History\SongSnapshotHelper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class SongTrashService {
	public function __construct(
		private InternalSongMapper $songMapper,
		private InternalEntityMapper $entityMapper,
		private InternalSongFileMapper $songFileMapper,
		private LibraryMapper $libraryMapper,
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ?IRootFolder $rootFolder = null,
		private ?InternalSongHistoryMapper $historyMapper = null,
		private ?SongSnapshotHelper $snapshotHelper = null,
	) {
	}

	/**
	 * List all soft-deleted songs for a library.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getDeletedSongs(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(InternalSongMapper::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('deleted_at'))
			->orderBy('deleted_at', 'DESC')
			->setMaxResults(100);

		$result = $qb->executeQuery();
		$deletedSongs = [];
		while ($row = $result->fetchAssociative()) {
			$song = InternalSong::fromRow($row);
			$songId = (int)$song->id;

			// Fetch composer names
			$composers = $this->entityMapper->findEntitiesForSong($songId, 'composer');
			$composerNames = array_map(static fn ($c) => $c->name, $composers);

			// Count attached files
			$files = $this->songFileMapper->findAllForSong($songId, includeDeleted: true);

			$deletedSongs[] = [
				'id' => $song->id,
				'uuid' => $song->uuid,
				'title' => $song->title,
				'composers' => $composerNames,
				'fileCount' => count($files),
				'deletedAt' => $song->deletedAt,
				'rating' => $song->rating,
			];
		}

		return $deletedSongs;
	}

	/**
	 * Restore a soft-deleted song and all its relationships.
	 */
	public function restoreDeletedSong(int $songId, ?string $userId = null): InternalSong {
		$song = $this->songMapper->findById($songId);
		if ($song === null) {
			throw new \InvalidArgumentException('Song not found');
		}

		$now = time();
		$song->deletedAt = null;
		$song->updatedAt = $now;
		$this->songMapper->update($song);

		// 1. Un-delete entity relations
		$qbLinks = $this->db->getQueryBuilder();
		$qbLinks->update(InternalEntityMapper::JUNCTION_TABLE_NAME)
			->set('deleted_at', $qbLinks->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qbLinks->expr()->eq('song_id', $qbLinks->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// 2. Un-delete files
		$qbFiles = $this->db->getQueryBuilder();
		$qbFiles->update(InternalSongFileMapper::TABLE_NAME)
			->set('deleted_at', $qbFiles->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qbFiles->expr()->eq('song_id', $qbFiles->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// Restore physical files from Nextcloud trashbin if they were deleted to trash
		$songFiles = $this->songFileMapper->findAllForSong($songId, includeDeleted: true);
		foreach ($songFiles as $fileRecord) {
			$this->restorePhysicalFileFromTrashIfNeeded($fileRecord->fileId, $userId);
		}

		// 3. Un-delete misc data
		$this->undeleteSongMiscData($song->uuid);

		$this->libraryMapper->touch($song->libraryId);

		// 4. Record restored history snapshot if available
		if ($this->historyMapper !== null && $this->snapshotHelper !== null) {
			$snapshot = $this->snapshotHelper->buildSnapshot($song);
			$history = new InternalSongHistory(
				id: null,
				libraryId: $song->libraryId,
				songId: (int)$song->id,
				songUuid: $song->uuid,
				userId: $userId,
				action: InternalSongHistory::ACTION_RESTORED,
				summary: 'Restored deleted song',
				snapshot: $snapshot,
				createdAt: time(),
			);
			$this->historyMapper->insert($history);
			$this->historyMapper->pruneOldEntries((int)$song->id, keep: 30);
		}

		return $song;
	}

	/**
	 * Restore a physical file from Nextcloud trashbin back to user folder.
	 */
	public function restorePhysicalFileFromTrashIfNeeded(int $ocFileId, ?string $userId): bool {
		if ($ocFileId <= 0 || $userId === null || $this->rootFolder === null) {
			return false;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($ocFileId);
			if (!empty($nodes)) {
				return true;
			}

			if ((interface_exists(\OCA\Files_Trashbin\Trash\ITrashManager::class) || class_exists(\OCA\Files_Trashbin\Trash\ITrashManager::class))
				&& \OC::$server->has(\OCA\Files_Trashbin\Trash\ITrashManager::class)
				&& \OC::$server->has(\OCP\IUserManager::class)) {
				/** @var \OCA\Files_Trashbin\Trash\ITrashManager $trashManager */
				$trashManager = \OC::$server->get(\OCA\Files_Trashbin\Trash\ITrashManager::class);
				/** @var \OCP\IUserManager $userManager */
				$userManager = \OC::$server->get(\OCP\IUserManager::class);
				$user = $userManager->get($userId);
				if ($user !== null) {
					$items = $trashManager->listTrashRoot($user);
					foreach ($items as $item) {
						if ((int)$item->getId() === $ocFileId) {
							$trashManager->restoreItem($item);
							$this->logger->info('Restored physical file from trash for song', [
								'fileId' => $ocFileId,
								'userId' => $userId,
							]);
							return true;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not restore physical file from trash: ' . $e->getMessage(), [
				'exception' => $e,
				'fileId' => $ocFileId,
				'userId' => $userId,
			]);
		}

		return false;
	}

	/**
	 * Un-delete misc data rows for a restored song.
	 */
	public function undeleteSongMiscData(string $songUuid): void {
		if ($songUuid === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('sheetmusic_misc_data')
			->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('song_uuid', $qb->createNamedParameter($songUuid, IQueryBuilder::PARAM_STR)))
			->executeStatement();
	}
}
