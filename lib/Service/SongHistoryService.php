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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class SongHistoryService {
	public function __construct(
		private InternalSongHistoryMapper $historyMapper,
		private InternalSongMapper $songMapper,
		private InternalEntityMapper $entityMapper,
		private InternalSongFileMapper $songFileMapper,
		private LibraryMapper $libraryMapper,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Build a full JSON-serializable snapshot of the song, including its entity relations and attached files.
	 *
	 * @return array<string, mixed>
	 */
	public function buildSnapshot(InternalSong $song): array {
		$songId = (int)$song->id;

		// Entities grouped by normalized type
		$entitiesByType = [];
		$allEntities = $songId > 0 ? $this->entityMapper->findEntitiesForSong($songId) : [];
		foreach ($allEntities as $entity) {
			$type = InternalEntityMapper::normalizeType($entity->type);
			$entitiesByType[$type][] = [
				'id' => $entity->id,
				'name' => $entity->name,
			];
		}

		// Files attached to song
		$files = [];
		$songFiles = $songId > 0 ? $this->songFileMapper->findAllForSong($songId, includeDeleted: true) : [];
		foreach ($songFiles as $file) {
			$files[] = [
				'id' => $file->id,
				'fileId' => $file->fileId,
				'pageOrder' => $file->pageOrder,
				'pageCount' => $file->pageCount,
				'fileSize' => $file->fileSize,
				'fileHash' => $file->fileHash,
				'type' => $file->type,
				'deletedAt' => $file->deletedAt,
			];
		}

		return [
			'id' => $song->id,
			'uuid' => $song->uuid,
			'libraryId' => $song->libraryId,
			'title' => $song->title,
			'sortTitle' => $song->sortTitle,
			'rating' => $song->rating,
			'difficulty' => $song->difficulty,
			'duration' => $song->duration,
			'keywords' => $song->keywords,
			'notes' => $song->notes,
			'opus' => $song->opus,
			'catalogNumber' => $song->catalogNumber,
			'songNumber' => $song->songNumber,
			'clientId' => $song->clientId,
			'deletedAt' => $song->deletedAt,
			'entities' => $entitiesByType,
			'files' => $files,
		];
	}

	/**
	 * Record a snapshot of a song in the timeline.
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
			$newSnapshot = $overrideSnapshot ?? $this->buildSnapshot($song);

			// Check previous snapshot to avoid duplicate entries for redundant saves
			$latest = $this->historyMapper->findLatestForSong($songId);
			if ($latest !== null && $action === InternalSongHistory::ACTION_UPDATED) {
				$oldSnapshot = $latest->getSnapshotArray();
				if ($this->areSnapshotsEquivalent($oldSnapshot, $newSnapshot)) {
					return $latest;
				}
			}

			if ($summary === null || trim($summary) === '') {
				$oldSnapshot = $latest !== null ? $latest->getSnapshotArray() : null;
				$summary = $this->generateSummary($action, $oldSnapshot, $newSnapshot);
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

		// 1. Revert core song fields
		$song->title = (string)($snap['title'] ?? $song->title);
		$song->sortTitle = isset($snap['sortTitle']) && $snap['sortTitle'] !== null ? (string)$snap['sortTitle'] : null;
		$song->rating = (int)($snap['rating'] ?? 0);
		$song->difficulty = (int)($snap['difficulty'] ?? 0);
		$song->duration = (int)($snap['duration'] ?? 0);
		$song->keywords = isset($snap['keywords']) && $snap['keywords'] !== null ? (string)$snap['keywords'] : null;
		$song->notes = isset($snap['notes']) && $snap['notes'] !== null ? (string)$snap['notes'] : null;
		$song->opus = isset($snap['opus']) && $snap['opus'] !== null ? (string)$snap['opus'] : null;
		$song->catalogNumber = isset($snap['catalogNumber']) && $snap['catalogNumber'] !== null ? (string)$snap['catalogNumber'] : null;
		$song->songNumber = isset($snap['songNumber']) && $snap['songNumber'] !== null ? (string)$snap['songNumber'] : null;

		// Undelete if song was deleted
		$wasDeleted = $song->deletedAt !== null;
		$song->deletedAt = null;
		$song->updatedAt = time();

		$this->songMapper->update($song);

		// 2. Revert entity relations
		$snapEntities = (array)($snap['entities'] ?? []);
		$knownTypes = [
			'composer', 'artist', 'book', 'collection', 'genre',
			'key', 'customgroup', 'year', 'signature', 'sourcetype', 'instrument',
		];

		foreach ($knownTypes as $type) {
			$items = (array)($snapEntities[$type] ?? []);
			$entityIds = [];
			foreach ($items as $item) {
				if (!empty($item['id'])) {
					$entityIds[] = (int)$item['id'];
				} elseif (!empty($item['name'])) {
					$existing = $this->entityMapper->findByName($song->libraryId, $type, (string)$item['name']);
					if ($existing !== null && $existing->id !== null) {
						$entityIds[] = (int)$existing->id;
					}
				}
			}
			$this->entityMapper->setSongEntitiesForType($songId, $type, array_unique($entityIds));
		}

		// 3. Revert files page orders and deleted flags
		if (!empty($snap['files']) && is_array($snap['files'])) {
			$currentFiles = $this->songFileMapper->findAllForSong($songId, includeDeleted: true);
			$fileMap = [];
			foreach ($currentFiles as $cf) {
				$fileMap[$cf->fileId] = $cf;
			}

			foreach ($snap['files'] as $fSnap) {
				$fileId = (int)($fSnap['fileId'] ?? 0);
				if ($fileId > 0 && isset($fileMap[$fileId])) {
					$fileRecord = $fileMap[$fileId];
					if (isset($fSnap['pageOrder'])) {
						$fileRecord->pageOrder = (string)$fSnap['pageOrder'];
					}
					// Restore file association if it was unlinked/soft-deleted
					$fileRecord->deletedAt = null;
					$fileRecord->updatedAt = time();
					$this->songFileMapper->update($fileRecord);
				}
			}
		}

		if ($wasDeleted) {
			// Also un-delete misc data rows for this song
			$this->undeleteSongMiscData($song->uuid);
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

		// 3. Un-delete misc data
		$this->undeleteSongMiscData($song->uuid);

		$this->libraryMapper->touch($song->libraryId);

		// Record restored history
		$this->recordSnapshot($song, InternalSongHistory::ACTION_RESTORED, $userId, 'Restored deleted song');

		return $song;
	}

	private function undeleteSongMiscData(string $songUuid): void {
		if ($songUuid === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('sheetmusic_misc_data')
			->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('song_uuid', $qb->createNamedParameter($songUuid, IQueryBuilder::PARAM_STR)))
			->executeStatement();
	}

	/**
	 * Compare two snapshots to see if meaningful fields changed.
	 */
	private function areSnapshotsEquivalent(array $a, array $b): bool {
		$keys = ['title', 'sortTitle', 'rating', 'difficulty', 'duration', 'keywords', 'notes', 'opus', 'catalogNumber', 'songNumber'];
		foreach ($keys as $k) {
			if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
				return false;
			}
		}

		// Check entities
		$entA = $a['entities'] ?? [];
		$entB = $b['entities'] ?? [];
		if (json_encode($entA) !== json_encode($entB)) {
			return false;
		}

		// Check files
		$filesA = $a['files'] ?? [];
		$filesB = $b['files'] ?? [];
		if (json_encode($filesA) !== json_encode($filesB)) {
			return false;
		}

		return true;
	}

	/**
	 * Generate a readable change summary between old and new state.
	 */
	private function generateSummary(string $action, ?array $old, array $new): string {
		if ($action === InternalSongHistory::ACTION_CREATED) {
			return 'Song created';
		}
		if ($action === InternalSongHistory::ACTION_DELETED) {
			return 'Song deleted';
		}
		if ($action === InternalSongHistory::ACTION_RESTORED) {
			return 'Song restored from trash';
		}
		if ($action === InternalSongHistory::ACTION_REVERTED) {
			return 'Reverted to revision';
		}
		if ($action === InternalSongHistory::ACTION_SYNCED) {
			return 'Synced from MobileSheets';
		}

		if ($old === null) {
			return 'Song modified';
		}

		$changes = [];

		if (($old['title'] ?? '') !== ($new['title'] ?? '')) {
			$changes[] = 'Title: "' . ($old['title'] ?? '') . '" → "' . ($new['title'] ?? '') . '"';
		}
		if (($old['rating'] ?? 0) !== ($new['rating'] ?? 0)) {
			$changes[] = 'Rating: ' . ($new['rating'] ?? 0) . '★';
		}
		if (($old['difficulty'] ?? 0) !== ($new['difficulty'] ?? 0)) {
			$changes[] = 'Difficulty: ' . ($new['difficulty'] ?? 0);
		}
		if (($old['duration'] ?? 0) !== ($new['duration'] ?? 0)) {
			$changes[] = 'Duration: ' . ($new['duration'] ?? 0) . 's';
		}
		if (($old['opus'] ?? '') !== ($new['opus'] ?? '')) {
			$changes[] = 'Opus updated';
		}
		if (($old['catalogNumber'] ?? '') !== ($new['catalogNumber'] ?? '')) {
			$changes[] = 'Catalog # updated';
		}

		// Check entity differences
		$oldEnts = (array)($old['entities'] ?? []);
		$newEnts = (array)($new['entities'] ?? []);
		foreach (['composer' => 'Composers', 'artist' => 'Artists', 'collection' => 'Collections', 'book' => 'Books'] as $t => $label) {
			$oldNames = array_column((array)($oldEnts[$t] ?? []), 'name');
			$newNames = array_column((array)($newEnts[$t] ?? []), 'name');
			sort($oldNames);
			sort($newNames);
			if ($oldNames !== $newNames) {
				$added = array_diff($newNames, $oldNames);
				$removed = array_diff($oldNames, $newNames);
				if (!empty($added)) {
					$changes[] = $label . ' added: ' . implode(', ', $added);
				}
				if (!empty($removed)) {
					$changes[] = $label . ' removed: ' . implode(', ', $removed);
				}
			}
		}

		// Check files
		$oldFiles = (array)($old['files'] ?? []);
		$newFiles = (array)($new['files'] ?? []);
		if (count($oldFiles) !== count($newFiles)) {
			$changes[] = 'Attached files: ' . count($oldFiles) . ' → ' . count($newFiles);
		}

		if (empty($changes)) {
			return 'Updated song details';
		}

		return implode('; ', array_slice($changes, 0, 3));
	}
}
