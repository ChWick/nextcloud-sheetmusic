<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongHistory;
use OCA\SheetMusic\Db\InternalSongHistoryMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\History\SongHistoryFileVersionService;
use OCA\SheetMusic\Service\History\SongSnapshotHelper;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class SongHistoryService {
	private SongSnapshotHelper $snapshotHelper;
	private SongHistoryFileVersionService $fileVersionService;
	private SongTrashService $trashService;

	public function __construct(
		private InternalSongHistoryMapper $historyMapper,
		private InternalSongMapper $songMapper,
		private InternalEntityMapper $entityMapper,
		private InternalSongFileMapper $songFileMapper,
		private LibraryMapper $libraryMapper,
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ?IRootFolder $rootFolder = null,
		private ?InternalSongAttributeMapper $songAttributeMapper = null,
		?SongSnapshotHelper $snapshotHelper = null,
		?SongTrashService $trashService = null,
		?SongHistoryFileVersionService $fileVersionService = null,
	) {
		$this->snapshotHelper = $snapshotHelper ?? new SongSnapshotHelper(
			$this->entityMapper,
			$this->songFileMapper,
			$this->songAttributeMapper,
		);
		$this->fileVersionService = $fileVersionService ?? new SongHistoryFileVersionService(
			$this->songFileMapper,
			$this->logger,
			$this->rootFolder,
		);
		$this->trashService = $trashService ?? new SongTrashService(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->db,
			$this->logger,
			$this->rootFolder,
			$this->historyMapper,
			$this->snapshotHelper,
		);
	}

	/**
	 * Build a full JSON-serializable snapshot of the song, including its entity relations and attached files.
	 *
	 * @return array<string, mixed>
	 */
	public function buildSnapshot(InternalSong $song): array {
		return $this->snapshotHelper->buildSnapshot($song);
	}

	/**
	 * Check whether a song already has at least one history entry.
	 */
	public function hasHistory(int $songId): bool {
		return $this->historyMapper->findLatestForSong($songId) !== null;
	}

	/**
	 * Record a snapshot of a song in the timeline.
	 *
	 * @param array<string, mixed>|null $overrideSnapshot
	 */
	public function recordSnapshot(
		InternalSong $song,
		string $action,
		?string $userId = null,
		?string $summary = null,
		?array $overrideSnapshot = null,
	): ?InternalSongHistory {
		$songId = (int)$song->id;
		if ($songId <= 0) {
			return null;
		}

		try {
			$newSnapshot = $overrideSnapshot ?? $this->snapshotHelper->buildSnapshot($song);

			// Check previous snapshot to avoid duplicate entries for redundant saves
			$latest = $this->historyMapper->findLatestForSong($songId);
			if ($latest !== null && $action === InternalSongHistory::ACTION_UPDATED) {
				$oldSnapshot = $latest->getSnapshotArray();
				if ($this->snapshotHelper->areSnapshotsEquivalent($oldSnapshot, $newSnapshot)) {
					return $latest;
				}
			}

			if ($summary === null || trim($summary) === '') {
				$oldSnapshot = $latest !== null ? $latest->getSnapshotArray() : null;
				$summary = $this->snapshotHelper->generateSummary($action, $oldSnapshot, $newSnapshot);
			}

			$history = new InternalSongHistory(
				id: null,
				libraryId: $song->libraryId,
				songId: $songId,
				songUuid: $song->uuid,
				userId: $userId,
				action: $action,
				summary: $summary,
				snapshot: $newSnapshot,
				createdAt: time(),
			);

			$saved = $this->historyMapper->insert($history);
			$this->historyMapper->pruneOldEntries($songId, keep: 30);

			return $saved;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to record song history snapshot', [
				'exception' => $e,
				'songId' => $song->id,
				'action' => $action,
			]);
			return null;
		}
	}

	/**
	 * Get all history timeline entries for a song.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getTimelineForSong(int $songId): array {
		$entries = $this->historyMapper->findForSong($songId);
		return array_map(static fn (InternalSongHistory $h) => $h->toArray(), $entries);
	}

	/**
	 * Revert a song to a previous history revision.
	 */
	public function revertSongToHistory(int $songId, int $historyId, ?string $userId = null): InternalSong {
		$history = $this->historyMapper->findById($historyId);
		if ($history === null || $history->songId !== $songId) {
			throw new \InvalidArgumentException('History entry not found for this song');
		}

		$song = $this->songMapper->findById($songId);
		if ($song === null) {
			throw new \InvalidArgumentException('Song not found');
		}

		$snap = $history->getSnapshotArray();

		// 1. Revert core and custom song properties dynamically using reflection
		$this->snapshotHelper->revertSongProperties($song, $snap);
		$wasDeleted = $song->deletedAt !== null;
		$song->deletedAt = null;
		$song->updatedAt = time();
		$this->songMapper->update($song);

		// 2. Revert entity relations dynamically across all present and historical entity types
		$this->snapshotHelper->revertSongEntities($song->libraryId, $songId, $snap);

		// 3. Revert files page orders, deleted flags, and physical file versions
		$this->revertSongFiles($songId, $snap, (int)$history->createdAt, $userId);

		// 4. Revert custom song attributes if mapper is available
		$this->snapshotHelper->revertSongAttributes($songId, $snap);

		if ($wasDeleted) {
			// Also un-delete misc data rows for this song
			$this->trashService->undeleteSongMiscData($song->uuid);
		}

		$this->libraryMapper->touch($song->libraryId);

		// Record a new snapshot representing the revert action
		$summary = 'Reverted to revision from ' . gmdate('Y-m-d H:i:s', (int)$history->createdAt) . ' UTC';
		$this->recordSnapshot($song, InternalSongHistory::ACTION_REVERTED, $userId, $summary);

		return $song;
	}

	/**
	 * List all soft-deleted songs for a library.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getDeletedSongs(int $libraryId): array {
		return $this->trashService->getDeletedSongs($libraryId);
	}

	/**
	 * Restore a soft-deleted song and all its relationships.
	 */
	public function restoreDeletedSong(int $songId, ?string $userId = null): InternalSong {
		return $this->trashService->restoreDeletedSong($songId, $userId);
	}

	/**
	 * Restore a physical file from Nextcloud trashbin back to user folder.
	 */
	public function restorePhysicalFileFromTrashIfNeeded(int $ocFileId, ?string $userId): bool {
		return $this->trashService->restorePhysicalFileFromTrashIfNeeded($ocFileId, $userId);
	}

	/**
	 * Restore earlier physical file version in Nextcloud files_versions if the file was modified since snapshot.
	 *
	 * @param array<string, mixed> $fileSnap
	 */
	public function restorePhysicalFileVersionIfNeeded(
		int $ocFileId,
		?string $userId,
		array $fileSnap,
		int $snapshotCreatedAt,
		InternalSongFile $fileRecord,
	): bool {
		return $this->fileVersionService->restorePhysicalFileVersionIfNeeded(
			$ocFileId,
			$userId,
			$fileSnap,
			$snapshotCreatedAt,
			$fileRecord
		);
	}

	/**
	 * Revert files page orders, deleted flags, and physical file versions for a snapshot.
	 *
	 * @param array<string, mixed> $snap
	 */
	private function revertSongFiles(int $songId, array $snap, int $historyCreatedAt, ?string $userId): void {
		if (empty($snap['files']) || !is_array($snap['files'])) {
			return;
		}

		$currentFiles = $this->songFileMapper->findAllForSong($songId, includeDeleted: true);
		$fileMap = [];
		foreach ($currentFiles as $cf) {
			$fileMap[$cf->fileId] = $cf;
		}

		$snapFileIds = [];
		foreach ($snap['files'] as $fSnap) {
			$fileId = (int)($fSnap['fileId'] ?? 0);
			if ($fileId <= 0) {
				continue;
			}

			$snapFileIds[] = $fileId;
			if (isset($fileMap[$fileId])) {
				$fileRecord = $fileMap[$fileId];
				if (isset($fSnap['pageOrder'])) {
					$fileRecord->pageOrder = (string)$fSnap['pageOrder'];
				}
				// Restore file association if it was unlinked/soft-deleted
				$fileRecord->deletedAt = null;
				$fileRecord->updatedAt = time();
				$this->songFileMapper->update($fileRecord);

				// Restore physical file from Nextcloud trashbin if it was moved to trash
				$this->trashService->restorePhysicalFileFromTrashIfNeeded($fileRecord->fileId, $userId);

				// Restore physical file version if the file was modified since this snapshot
				$this->fileVersionService->restorePhysicalFileVersionIfNeeded(
					$fileRecord->fileId,
					$userId,
					$fSnap,
					$historyCreatedAt,
					$fileRecord
				);
			}
		}

		// Soft-delete any files that were attached after this snapshot
		foreach ($currentFiles as $cf) {
			if (!in_array($cf->fileId, $snapFileIds, true) && $cf->deletedAt === null) {
				$cf->deletedAt = time();
				$cf->updatedAt = time();
				$this->songFileMapper->update($cf);
			}
		}
	}
}
