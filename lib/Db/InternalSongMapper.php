<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsArtist;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBook;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCollection;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCustomGroup;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsGenre;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsKey;
use OCA\SheetMusic\Db\InternalSublibrary;
use OCA\SheetMusic\Service\SublibraryService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSignature;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSourceType;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsYear;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;

class InternalSongMapper {
	public const TABLE_NAME = 'sheetmusic_songs';

	public function __construct(
		private IDBConnection $db,
		private ?InternalEntityMapper $entityMapper = null,
		private ?InternalSongFileMapper $songFileMapper = null,
		private ?IRootFolder $rootFolder = null,
		private ?InternalSongBookmarkMapper $bookmarkMapper = null,
		private ?SublibraryService $sublibraryService = null,
		private ?LibraryMapper $libraryMapper = null,
	) {
	}

	/**
	 * WebDAV path of every file id as the user sees it, keyed by file id.
	 *
	 * The filecache stores paths relative to the *storage*, not to the user's
	 * folder. For the user's home storage that is "files/<relative>", but files on
	 * other mounts - team folders ("__groupfolders/3/..."), shares, external
	 * storage - live in a different storage whose cache path has no relation to
	 * where the mount appears in the user's folder. Those are resolved through the
	 * user folder, which knows the mount points.
	 *
	 * Files the user cannot reach are left out, so callers treat them as missing.
	 *
	 * @param list<int> $fileIds
	 * @return array<int, string>
	 */
	private function resolveFileNodePaths(array $fileIds, ?IUser $user): array {
		if ($fileIds === []) {
			return [];
		}

		$uid = $user?->getUID() ?? '';
		$webdavRoot = MobileSheetsPathResolver::WEBDAV_ROOT . '/' . $uid;

		$userFolder = null;
		$homeStorageId = null;
		$homePrefix = 'files';
		if ($user !== null && $this->rootFolder !== null) {
			try {
				$userFolder = $this->rootFolder->getUserFolder($uid);
				$homeStorageId = $userFolder->getMountPoint()->getNumericStorageId();
				$homePrefix = trim($userFolder->getInternalPath(), '/');
			} catch (\Throwable) {
				$userFolder = null;
				$homeStorageId = null;
			}
		}

		$paths = [];
		// "<storage>:<cache dir>" => directory relative to the user folder; lets
		// siblings on a foreign mount reuse one lookup instead of one per file.
		$dirMap = [];
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid', 'path', 'storage')
				->from('filecache')
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			$rows = $result->fetchAllAssociative();
			$result->closeCursor();
		} catch (\Throwable) {
			return [];
		}

		foreach ($rows as $r) {
			$fileId = (int)$r['fileid'];
			$cachePath = ltrim((string)$r['path'], '/');
			$storageId = (int)$r['storage'];

			// Fast path: file in the user's own home storage
			if ($homeStorageId !== null && $storageId === $homeStorageId && str_starts_with($cachePath, $homePrefix . '/')) {
				$paths[$fileId] = $webdavRoot . '/' . substr($cachePath, strlen($homePrefix) + 1);
				continue;
			}

			// No user folder to resolve mounts with: legacy best effort
			if ($userFolder === null) {
				$p = str_starts_with($cachePath, 'files/') ? substr($cachePath, 6) : $cachePath;
				$paths[$fileId] = $webdavRoot . '/' . $p;
				continue;
			}

			$cacheDir = dirname($cachePath);
			$dirKey = $storageId . ':' . $cacheDir;
			if (isset($dirMap[$dirKey])) {
				$dir = $dirMap[$dirKey];
				$paths[$fileId] = $webdavRoot . '/' . ($dir === '' ? '' : $dir . '/') . basename($cachePath);
				continue;
			}

			try {
				$node = $userFolder->getFirstNodeById($fileId);
				if ($node === null) {
					continue;
				}
				$relative = $userFolder->getRelativePath($node->getPath());
				if ($relative === null) {
					continue;
				}
				$relative = trim($relative, '/');
				$paths[$fileId] = $webdavRoot . '/' . $relative;

				// The parent directory is only visible as a whole when the node is
				// not itself the root of its mount (e.g. a single shared file).
				$mountRoot = rtrim($node->getMountPoint()->getMountPoint(), '/');
				if ($mountRoot !== rtrim($node->getPath(), '/')) {
					$relDir = dirname($relative);
					$dirMap[$dirKey] = $relDir === '.' ? '' : $relDir;
				}
			} catch (\Throwable) {
				// Unreachable for this user: reported as missing
			}
		}

		return $paths;
	}

	/**
	 * Resolves WebDAV paths for files viewed through a virtual context (public share link or shared sub-library).
	 *
	 * @param list<InternalSongFile> $files
	 * @return array<int, string>
	 */
	private function resolveVirtualFileNodePaths(
		array $files,
		InternalLibrary $library,
		?IUser $user = null,
		?string $publicToken = null,
		?int $parentLibraryId = null,
	): array {
		if ($files === [] || $this->rootFolder === null) {
			return [];
		}

		$paths = [];
		try {
			$ownerUserId = $library->ownerId ?? $library->userId;
			$ownerFolder = $this->rootFolder->getUserFolder($ownerUserId);
			$libraryMapper = $this->libraryMapper ?? new LibraryMapper($this->db);
			$parentLib = $parentLibraryId !== null ? $libraryMapper->findById($parentLibraryId) : null;
			$parentPath = $parentLib !== null ? $parentLib->path : $library->path;
			if (str_contains($parentPath, ' / ')) {
				$parts = explode(' / ', $parentPath, 2);
				$parentPath = $parts[0];
			}
			$libPath = trim($parentPath, '/');
			if (str_ends_with(strtolower($libPath), '.db')) {
				$libPath = dirname($libPath);
				$libPath = ($libPath === '.' ? '' : $libPath);
			}
			$baseNode = $libPath === '' ? $ownerFolder : $ownerFolder->get($libPath);

			$prefix = $publicToken !== null
				? ('/public.php/dav/files/' . $publicToken)
				: (MobileSheetsPathResolver::WEBDAV_ROOT . '/' . ($user?->getUID() ?? '') . (trim($library->path, '/') === '' ? '' : '/' . trim($library->path, '/')));

			foreach ($files as $file) {
				try {
					$rootNodes = $this->rootFolder->getById($file->fileId);
					if (!empty($rootNodes)) {
						$rel = $baseNode->getRelativePath($rootNodes[0]->getPath());
						if ($rel !== null && $rel !== false) {
							$paths[$file->fileId] = $prefix . '/' . ltrim($rel, '/');
						}
					}
				} catch (\Throwable) {
					// Missing file
				}
			}
		} catch (\Throwable) {
			// Folder lookup failed
		}

		return $paths;
	}

	public function findById(int $id): ?InternalSong {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSong::fromRow($row);
	}

	public function findSong(int $songId, ?IUser $user = null): ?MobileSheetsSong {
		$internalSong = $this->findById($songId);
		if ($internalSong === null) {
			return null;
		}

		$data = $this->findLibraryData($internalSong->libraryId, $user);
		foreach ($data->songs as $song) {
			if ($song->id === $songId) {
				return $song;
			}
		}

		return null;
	}

	public function findByUuid(string $uuid): ?InternalSong {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSong::fromRow($row);
	}

	public function findByTitle(int $libraryId, string $title): ?InternalSong {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSong::fromRow($row);
	}

	/**
	 * @return list<InternalSong>
	 */
	public function findAllForLibrary(int $libraryId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		$qb->orderBy('title', 'ASC');

		$result = $qb->executeQuery();
		$songs = [];
		while ($row = $result->fetchAssociative()) {
			$songs[] = InternalSong::fromRow($row);
		}
		$result->closeCursor();

		return $songs;
	}

	public function findByClientId(int $libraryId, int $clientId): ?InternalSong {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSong::fromRow($row);
	}

	public function insert(InternalSong $song): InternalSong {
		$now = time();
		if ($song->createdAt === 0) {
			$song->createdAt = $now;
		}
		if ($song->updatedAt === 0) {
			$song->updatedAt = $now;
		}

		$song->rating = ($song->rating !== null && $song->rating > 0) ? $song->rating : null;
		$song->difficulty = ($song->difficulty !== null && $song->difficulty > 0) ? $song->difficulty : null;
		$song->duration = ($song->duration !== null && $song->duration > 0) ? $song->duration : null;

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'uuid' => $qb->createNamedParameter($song->uuid, IQueryBuilder::PARAM_STR),
				'library_id' => $qb->createNamedParameter($song->libraryId, IQueryBuilder::PARAM_INT),
				'title' => $qb->createNamedParameter($song->title, IQueryBuilder::PARAM_STR),
				'sort_title' => $qb->createNamedParameter($song->sortTitle, IQueryBuilder::PARAM_STR),
				'rating' => $qb->createNamedParameter($song->rating, IQueryBuilder::PARAM_INT),
				'difficulty' => $qb->createNamedParameter($song->difficulty, IQueryBuilder::PARAM_INT),
				'duration' => $qb->createNamedParameter($song->duration, IQueryBuilder::PARAM_INT),
				'keywords' => $qb->createNamedParameter($song->keywords, IQueryBuilder::PARAM_STR),
				'notes' => $qb->createNamedParameter($song->notes, IQueryBuilder::PARAM_STR),
				'opus' => $qb->createNamedParameter($song->opus, IQueryBuilder::PARAM_STR),
				'catalog_number' => $qb->createNamedParameter($song->catalogNumber, IQueryBuilder::PARAM_STR),
				'song_number' => $qb->createNamedParameter($song->songNumber, IQueryBuilder::PARAM_STR),
				'client_id' => $qb->createNamedParameter($song->clientId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($song->createdAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($song->updatedAt, IQueryBuilder::PARAM_INT),
				'deleted_at' => $qb->createNamedParameter($song->deletedAt, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
		$song->id = (int)$this->db->lastInsertId(self::TABLE_NAME);

		return $song;
	}

	public function update(InternalSong $song): InternalSong {
		if ($song->id === null) {
			throw new \InvalidArgumentException('Cannot update a song without an ID');
		}

		if ($song->updatedAt === null || $song->updatedAt <= 0) {
			$song->updatedAt = time();
		}

		$song->rating = ($song->rating !== null && $song->rating > 0) ? $song->rating : null;
		$song->difficulty = ($song->difficulty !== null && $song->difficulty > 0) ? $song->difficulty : null;
		$song->duration = ($song->duration !== null && $song->duration > 0) ? $song->duration : null;

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('title', $qb->createNamedParameter($song->title, IQueryBuilder::PARAM_STR))
			->set('sort_title', $qb->createNamedParameter($song->sortTitle, IQueryBuilder::PARAM_STR))
			->set('rating', $qb->createNamedParameter($song->rating, IQueryBuilder::PARAM_INT))
			->set('difficulty', $qb->createNamedParameter($song->difficulty, IQueryBuilder::PARAM_INT))
			->set('duration', $qb->createNamedParameter($song->duration, IQueryBuilder::PARAM_INT))
			->set('keywords', $qb->createNamedParameter($song->keywords, IQueryBuilder::PARAM_STR))
			->set('notes', $qb->createNamedParameter($song->notes, IQueryBuilder::PARAM_STR))
			->set('opus', $qb->createNamedParameter($song->opus, IQueryBuilder::PARAM_STR))
			->set('catalog_number', $qb->createNamedParameter($song->catalogNumber, IQueryBuilder::PARAM_STR))
			->set('song_number', $qb->createNamedParameter($song->songNumber, IQueryBuilder::PARAM_STR))
			->set('client_id', $qb->createNamedParameter($song->clientId, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($song->updatedAt, IQueryBuilder::PARAM_INT))
			->set('deleted_at', $qb->createNamedParameter($song->deletedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($song->id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();

		return $song;
	}

	public function delete(int $id, bool $hardDelete = false): bool {
		$qb = $this->db->getQueryBuilder();

		if ($hardDelete) {
			$unlinkQb = $this->db->getQueryBuilder();
			$unlinkQb->delete(InternalEntityMapper::JUNCTION_TABLE_NAME)
				->where($unlinkQb->expr()->eq('song_id', $unlinkQb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$unlinkQb->executeStatement();

			$attrQb = $this->db->getQueryBuilder();
			$attrQb->delete(InternalSongAttributeMapper::TABLE_NAME)
				->where($attrQb->expr()->eq('song_id', $attrQb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$attrQb->executeStatement();

			$qb->delete(self::TABLE_NAME)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		} else {
			$now = time();
			$qb->update(self::TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

			$unlinkQb = $this->db->getQueryBuilder();
			$unlinkQb->update(InternalEntityMapper::JUNCTION_TABLE_NAME)
				->set('deleted_at', $unlinkQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($unlinkQb->expr()->eq('song_id', $unlinkQb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($unlinkQb->expr()->isNull('deleted_at'));
			$unlinkQb->executeStatement();
		}

		return $qb->executeStatement() > 0;
	}

	public function mergeSongs(int $libraryId, int $keepId, int $droppedId): void {
		if ($keepId === $droppedId) {
			return;
		}

		$now = time();

		// 1. Move files from droppedId to keepId, deduplicating shared file_ids
		if ($this->songFileMapper !== null) {
			$keepFiles = $this->songFileMapper->findAllForSong($keepId);
			$keepFileIds = [];
			foreach ($keepFiles as $kf) {
				if ($kf->fileId > 0) {
					$keepFileIds[$kf->fileId] = true;
				}
			}

			$droppedFiles = $this->songFileMapper->findAllForSong($droppedId);
			foreach ($droppedFiles as $df) {
				if ($df->id === null) {
					continue;
				}
				if ($df->fileId > 0 && isset($keepFileIds[$df->fileId])) {
					$this->songFileMapper->delete($df->id);
				} else {
					$qbMoveFile = $this->db->getQueryBuilder();
					$qbMoveFile->update(InternalSongFileMapper::TABLE_NAME)
						->set('song_id', $qbMoveFile->createNamedParameter($keepId, IQueryBuilder::PARAM_INT))
						->set('updated_at', $qbMoveFile->createNamedParameter($now, IQueryBuilder::PARAM_INT))
						->where($qbMoveFile->expr()->eq('id', $qbMoveFile->createNamedParameter($df->id, IQueryBuilder::PARAM_INT)));
					$qbMoveFile->executeStatement();
					if ($df->fileId > 0) {
						$keepFileIds[$df->fileId] = true;
					}
				}
			}
		} else {
			$qbFiles = $this->db->getQueryBuilder();
			$qbFiles->update(InternalSongFileMapper::TABLE_NAME)
				->set('song_id', $qbFiles->createNamedParameter($keepId, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qbFiles->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qbFiles->expr()->eq('song_id', $qbFiles->createNamedParameter($droppedId, IQueryBuilder::PARAM_INT)))
				->andWhere($qbFiles->expr()->isNull('deleted_at'));
			$qbFiles->executeStatement();
		}

		// 2. Move entity links from droppedId to keepId
		if ($this->entityMapper !== null) {
			$entityIds = $this->entityMapper->findEntityIdsForSong($droppedId);
			foreach ($entityIds as $entId) {
				$this->entityMapper->linkSongEntity($keepId, $entId);
				$this->entityMapper->unlinkSongEntity($droppedId, $entId, true);
			}
		}

		// 2b. Move bookmarks from droppedId to keepId
		$bmQb = $this->db->getQueryBuilder();
		$bmQb->update(InternalSongBookmarkMapper::TABLE_NAME)
			->set('song_id', $bmQb->createNamedParameter($keepId, IQueryBuilder::PARAM_INT))
			->set('updated_at', $bmQb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($bmQb->expr()->eq('song_id', $bmQb->createNamedParameter($droppedId, IQueryBuilder::PARAM_INT)));
		$bmQb->executeStatement();

		// 3. Remove attributes and misc_data of dropped song, bump keepId updated_at, and delete dropped song
		$droppedSong = $this->findById($droppedId);
		if ($droppedSong !== null) {
			$miscQb = $this->db->getQueryBuilder();
			$miscQb->update(InternalMiscMapper::DATA_TABLE_NAME)
				->set('deleted_at', $miscQb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->set('updated_at', $miscQb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($miscQb->expr()->eq('library_id', $miscQb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
				->andWhere($miscQb->expr()->eq('song_uuid', $miscQb->createNamedParameter($droppedSong->uuid, IQueryBuilder::PARAM_STR)));
			$miscQb->executeStatement();
		}

		$attrQb = $this->db->getQueryBuilder();
		$attrQb->delete(InternalSongAttributeMapper::TABLE_NAME)
			->where($attrQb->expr()->eq('song_id', $attrQb->createNamedParameter($droppedId, IQueryBuilder::PARAM_INT)));
		$attrQb->executeStatement();

		$qbKeep = $this->db->getQueryBuilder();
		$qbKeep->update(self::TABLE_NAME)
			->set('updated_at', $qbKeep->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qbKeep->expr()->eq('id', $qbKeep->createNamedParameter($keepId, IQueryBuilder::PARAM_INT)));
		$qbKeep->executeStatement();

		$this->delete($droppedId);
	}

	/**
	 * Clean up auxiliary attributes and misc_data records associated with a deleted song.
	 */
	public function cleanupDeletedSongData(int $libraryId, int $songId): void {
		$song = $this->findById($songId);
		$attrQb = $this->db->getQueryBuilder();
		$attrQb->delete(InternalSongAttributeMapper::TABLE_NAME)
			->where($attrQb->expr()->eq('song_id', $attrQb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));
		$attrQb->executeStatement();

		$bmQb = $this->db->getQueryBuilder();
		$bmQb->delete(InternalSongBookmarkMapper::TABLE_NAME)
			->where($bmQb->expr()->eq('song_id', $bmQb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));
		$bmQb->executeStatement();

		$siQb = $this->db->getQueryBuilder();
		$siQb->delete(InternalSetlistItemMapper::TABLE_NAME)
			->where($siQb->expr()->eq('song_id', $siQb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));
		$siQb->executeStatement();

		if ($song !== null) {
			$now = time();
			$miscQb = $this->db->getQueryBuilder();
			$miscQb->update(InternalMiscMapper::DATA_TABLE_NAME)
				->set('deleted_at', $miscQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $miscQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($miscQb->expr()->eq('library_id', $miscQb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
				->andWhere($miscQb->expr()->eq('song_uuid', $miscQb->createNamedParameter($song->uuid, IQueryBuilder::PARAM_STR)));
			$miscQb->executeStatement();
		}
	}

	/**
	 * Find active songs sharing the same client_id in the library.
	 *
	 * @return list<DuplicateSongClientIdConflict>
	 */
	public function findDuplicateClientIds(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'client_id', 'title')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->isNotNull('client_id'))
			->andWhere($qb->expr()->gt('client_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$grouped = [];
		while ($row = $result->fetchAssociative()) {
			$cId = (int)$row['client_id'];
			$grouped[$cId][] = [
				'id' => (int)$row['id'],
				'title' => (string)$row['title'],
			];
		}
		$result->closeCursor();

		$conflicts = [];
		foreach ($grouped as $cId => $entries) {
			if (count($entries) > 1) {
				$conflicts[] = new DuplicateSongClientIdConflict(
					clientId: $cId,
					songIds: array_column($entries, 'id'),
					titles: array_column($entries, 'title'),
				);
			}
		}

		return $conflicts;
	}

	/**
	 * Re-assign unique client_ids to songs that have conflicting duplicates.
	 *
	 * @return int number of reassigned songs
	 */
	public function fixDuplicateClientIds(int $libraryId): int {
		$conflicts = $this->findDuplicateClientIds($libraryId);
		if ($conflicts === []) {
			return 0;
		}

		$qbMax = $this->db->getQueryBuilder();
		$qbMax->select($qbMax->createFunction('MAX(' . $qbMax->getColumnName('client_id') . ')'))
			->from(self::TABLE_NAME)
			->where($qbMax->expr()->eq('library_id', $qbMax->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
		$maxId = (int)$qbMax->executeQuery()->fetchOne();
		if ($maxId < 1) {
			$maxId = 1;
		}

		$reassigned = 0;
		foreach ($conflicts as $conflict) {
			$duplicates = array_slice($conflict->songIds, 1);
			foreach ($duplicates as $sId) {
				$maxId++;
				$qbUp = $this->db->getQueryBuilder();
				$qbUp->update(self::TABLE_NAME)
					->set('client_id', $qbUp->createNamedParameter($maxId, IQueryBuilder::PARAM_INT))
					->set('updated_at', $qbUp->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
					->where($qbUp->expr()->eq('id', $qbUp->createNamedParameter($sId, IQueryBuilder::PARAM_INT)));
				$reassigned += $qbUp->executeStatement();
			}
		}

		return $reassigned;
	}

	/**
	 * Find active songs that have no active files attached.
	 *
	 * @return list<SongWithoutFiles>
	 */
	public function findSongsWithoutFiles(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.id', 's.title', 's.uuid')
			->from(self::TABLE_NAME, 's')
			->leftJoin('s', InternalSongFileMapper::TABLE_NAME, 'f', $qb->expr()->andX(
				$qb->expr()->eq('s.id', 'f.song_id'),
				$qb->expr()->isNull('f.deleted_at')
			))
			->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('s.deleted_at'))
			->andWhere($qb->expr()->isNull('f.id'))
			->orderBy('s.id', 'ASC');

		$result = $qb->executeQuery();
		$songs = [];
		while ($row = $result->fetchAssociative()) {
			$songs[] = new SongWithoutFiles(
				id: (int)$row['id'],
				title: (string)$row['title'],
				uuid: (string)$row['uuid'],
			);
		}
		$result->closeCursor();

		return $songs;
	}

	/**
	 * @return array{
	 *     songs: list<MobileSheetsSong>,
	 *     composers: list<MobileSheetsComposer>,
	 *     books: list<MobileSheetsBook>,
	 *     collections: list<MobileSheetsCollection>,
	 *     artists: list<MobileSheetsArtist>,
	 *     genres: list<MobileSheetsGenre>,
	 *     keys: list<MobileSheetsKey>,
	 *     customGroups: list<MobileSheetsCustomGroup>,
	 *     years: list<MobileSheetsYear>,
	 *     signatures: list<MobileSheetsSignature>,
	 *     sourceTypes: list<MobileSheetsSourceType>,
	 *     instruments: list<MobileSheetsCustomGroup>,
	 *     arrangers: list<MobileSheetsCustomGroup>
	 * }
	 */
	public function findLibraryData(
		int $libraryId,
		?IUser $user = null,
		?string $publicToken = null,
		?InternalLibrary $library = null,
		?InternalSublibrary $sublibrary = null,
	): LibraryData {
		$entities = $this->entityMapper !== null
			? $this->entityMapper->findAllForLibrary($libraryId)
			: [];

		$groupedEntities = [];
		$entitiesById = [];

		foreach ($entities as $e) {
			$eid = (int)$e->id;
			$entitiesById[$eid] = $e;
			$type = InternalEntityMapper::normalizeType($e->type);
			$groupedEntities[$type][] = $e->toMobileSheetsEntity($type);
		}

		$songs = $this->findAllForLibrary($libraryId);

		$songEntities = [];
		if ($this->entityMapper !== null) {
			$links = $this->entityMapper->findAllSongEntityLinksForLibrary($libraryId);
			foreach ($links as $link) {
				$normType = InternalEntityMapper::normalizeType($link->type);
				$songEntities[$link->songId][$normType][] = $link->entityId;
			}
		}

		$files = $this->songFileMapper !== null
			? $this->songFileMapper->findAllForLibrary($libraryId)
			: [];

		$songBookmarks = $this->bookmarkMapper !== null
			? $this->bookmarkMapper->findAllForLibrary($libraryId)
			: [];

		if ($sublibrary !== null && $this->sublibraryService !== null) {
			$matchingIds = $this->sublibraryService->getMatchingSongIds($sublibrary);
			$songs = array_values(array_filter($songs, fn($s) => isset($matchingIds[(int)$s->id])));
			$files = array_values(array_filter($files, fn($f) => isset($matchingIds[(int)$f->songId])));
			$songBookmarks = array_values(array_filter($songBookmarks, fn($b) => isset($matchingIds[(int)$b->songId])));
			$songEntities = array_intersect_key($songEntities, $matchingIds);
		}

		$fileIds = array_values(array_unique(array_filter(array_column($files, 'fileId'))));
		$uid = $user?->getUID() ?? '';
		$fileNodePaths = [];
		if ($publicToken !== null && $library !== null) {
			$fileNodePaths = $this->resolveVirtualFileNodePaths($files, $library, publicToken: $publicToken, parentLibraryId: $libraryId);
		} elseif ($user !== null) {
			$fileNodePaths = $this->resolveFileNodePaths($fileIds, $user);
			if ($library !== null && ($library->sublibraryId !== null || $sublibrary !== null)) {
				$virtualPaths = $this->resolveVirtualFileNodePaths($files, $library, user: $user, parentLibraryId: $libraryId);
				foreach ($virtualPaths as $fid => $p) {
					$fileNodePaths[$fid] = $p;
				}
			} elseif ($library !== null && $library->isShared) {
				$virtualPaths = $this->resolveVirtualFileNodePaths($files, $library, user: $user, parentLibraryId: $libraryId);
				foreach ($virtualPaths as $fid => $p) {
					$fileNodePaths[$fid] ??= $p;
				}
			}
		}

		$songFiles = [];
		foreach ($files as $file) {
			$path = $fileNodePaths[$file->fileId] ?? ($publicToken !== null
				? ('/public.php/dav/files/' . $publicToken . '/file_' . $file->fileId . '.pdf')
				: (MobileSheetsPathResolver::WEBDAV_ROOT . '/' . $uid . '/file_' . $file->fileId . '.pdf'));
			$songFiles[$file->songId][] = new MobileSheetsFile(
				id: (int)$file->id,
				path: $path,
				pageOrder: $file->pageOrder,
				pageCount: (int)$file->pageCount,
				fileId: (int)$file->fileId,
				updatedAt: $file->updatedAt > 0
					? ($file->updatedAt < 100000000000 ? $file->updatedAt * 1000 : $file->updatedAt)
					: null,
			);
		}

		$resultSongs = [];
		foreach ($songs as $song) {
			$sid = (int)$song->id;

			$resolve = function (string $type) use ($songEntities, $sid, $entitiesById): array {
				$ids = $songEntities[$sid][$type] ?? [];
				$res = [];
				foreach ($ids as $id) {
					if (isset($entitiesById[$id])) {
						$res[] = $entitiesById[$id]->toMobileSheetsEntity($type);
					}
				}
				return $res;
			};

			$resultSongs[] = new MobileSheetsSong(
				id: $sid,
				title: $song->title,
				files: $songFiles[$sid] ?? [],
				composers: $resolve('composer'),
				books: $resolve('book'),
				collections: $resolve('collection'),
				artists: $resolve('artist'),
				genres: $resolve('genre'),
				keys: $resolve('key'),
				customGroups: $resolve('customgroup'),
				years: $resolve('year'),
				signatures: $resolve('signature'),
				sourceTypes: $resolve('sourcetype'),
				bookmarks: array_map(
					static fn (InternalSongBookmark $bm) => $bm->toMobileSheetsBookmark(),
					$songBookmarks[$sid] ?? []
				),
				audioFiles: [],
				lastModified: $song->updatedAt > 0
					? ($song->updatedAt < 100000000000 ? $song->updatedAt * 1000 : $song->updatedAt)
					: null,
				dateCreated: $song->createdAt > 0
					? ($song->createdAt < 100000000000 ? $song->createdAt * 1000 : $song->createdAt)
					: null,
				stars: $song->rating,
				difficulty: $song->difficulty,
				duration: $song->duration,
				keywords: $song->keywords ?? '',
				custom: $song->notes ?? '',
				custom2: '',
				sortTitle: $song->sortTitle ?? '',
				opus: $song->opus ?? '',
				catalogNumber: $song->catalogNumber ?? '',
				songNumber: $song->songNumber ?? '',
				instruments: $resolve('instrument'),
				arrangers: $resolve('arranger'),
			);
		}

		return new LibraryData(
			songs: $resultSongs,
			composers: $groupedEntities['composer'] ?? [],
			books: $groupedEntities['book'] ?? [],
			collections: $groupedEntities['collection'] ?? [],
			artists: $groupedEntities['artist'] ?? [],
			genres: $groupedEntities['genre'] ?? [],
			keys: $groupedEntities['key'] ?? [],
			customGroups: $groupedEntities['customgroup'] ?? [],
			years: $groupedEntities['year'] ?? [],
			signatures: $groupedEntities['signature'] ?? [],
			sourceTypes: $groupedEntities['sourcetype'] ?? [],
			instruments: $groupedEntities['instrument'] ?? [],
			arrangers: $groupedEntities['arranger'] ?? [],
		);
	}
}
