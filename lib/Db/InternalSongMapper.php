<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCA\SheetMusic\Service\MobileSheetsPathResolver;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;

class InternalSongMapper {
	public const TABLE_NAME = 'sheetmusic_songs';

	public function __construct(
		private IDBConnection $db,
		private ?InternalEntityMapper $entityMapper = null,
		private ?InternalSongFileMapper $songFileMapper = null,
	) {
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

		// 1. Move files from droppedId to keepId
		$qbFiles = $this->db->getQueryBuilder();
		$qbFiles->update(InternalSongFileMapper::TABLE_NAME)
			->set('song_id', $qbFiles->createNamedParameter($keepId, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qbFiles->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qbFiles->expr()->eq('song_id', $qbFiles->createNamedParameter($droppedId, IQueryBuilder::PARAM_INT)))
			->andWhere($qbFiles->expr()->isNull('deleted_at'));
		$qbFiles->executeStatement();

		// 2. Move entity links from droppedId to keepId
		if ($this->entityMapper !== null) {
			$qbLinks = $this->db->getQueryBuilder();
			$qbLinks->select('entity_id')
				->from(InternalEntityMapper::JUNCTION_TABLE_NAME)
				->where($qbLinks->expr()->eq('song_id', $qbLinks->createNamedParameter($droppedId, IQueryBuilder::PARAM_INT)))
				->andWhere($qbLinks->expr()->isNull('deleted_at'));
			$res = $qbLinks->executeQuery();
			$entityIds = [];
			while ($row = $res->fetchAssociative()) {
				$entityIds[] = (int)$row['entity_id'];
			}
			$res->closeCursor();

			foreach ($entityIds as $entId) {
				$this->entityMapper->linkSongEntity($keepId, $entId);
				$this->entityMapper->unlinkSongEntity($droppedId, $entId, true);
			}
		}

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
	 *     instruments: list<MobileSheetsCustomGroup>
	 * }
	 */
	public function findLibraryData(int $libraryId, ?IUser $user = null): LibraryData {
		$entities = $this->entityMapper !== null
			? $this->entityMapper->findAllForLibrary($libraryId)
			: [];

		$composers = [];
		$books = [];
		$collections = [];
		$artists = [];
		$genres = [];
		$keys = [];
		$customGroups = [];
		$years = [];
		$signatures = [];
		$sourceTypes = [];
		$instruments = [];
		$entitiesById = [];

		foreach ($entities as $e) {
			$eid = (int)$e->id;
			$entitiesById[$eid] = $e;
			$type = InternalEntityMapper::normalizeType($e->type);
			$msEntity = $e->toMobileSheetsEntity($type);

			match ($type) {
				'composer' => $composers[] = $msEntity,
				'book' => $books[] = $msEntity,
				'collection' => $collections[] = $msEntity,
				'artist' => $artists[] = $msEntity,
				'genre' => $genres[] = $msEntity,
				'key' => $keys[] = $msEntity,
				'customgroup' => $customGroups[] = $msEntity,
				'year' => $years[] = $msEntity,
				'signature' => $signatures[] = $msEntity,
				'sourcetype' => $sourceTypes[] = $msEntity,
				'instrument' => $instruments[] = $msEntity,
				default => null,
			};
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

		$fileNodePaths = [];
		$fileIds = array_values(array_unique(array_filter(array_map(fn (InternalSongFile $f) => $f->fileId, $files))));
		if (!empty($fileIds)) {
			try {
				$qbCache = $this->db->getQueryBuilder();
				$qbCache->select('fileid', 'path')
					->from('filecache')
					->where($qbCache->expr()->in('fileid', $qbCache->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
				$resCache = $qbCache->executeQuery();
				while ($r = $resCache->fetchAssociative()) {
					$p = (string)$r['path'];
					if (str_starts_with($p, 'files/')) {
						$p = substr($p, 6);
					}
					$uid = $user !== null ? $user->getUID() : '';
					$fileNodePaths[(int)$r['fileid']] = MobileSheetsPathResolver::WEBDAV_ROOT . '/' . $uid . '/' . ltrim($p, '/');
				}
				$resCache->closeCursor();
			} catch (\Throwable) {
				// Fall through
			}
		}

		$songFiles = [];
		foreach ($files as $file) {
			$path = $fileNodePaths[$file->fileId] ?? (MobileSheetsPathResolver::WEBDAV_ROOT . '/' . ($user !== null ? $user->getUID() : '') . '/file_' . $file->fileId . '.pdf');
			$songFiles[$file->songId][] = new MobileSheetsFile(
				id: (int)$file->id,
				path: $path,
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
				bookmarks: [],
				audioFiles: [],
				lastModified: $song->updatedAt > 0 ? $song->updatedAt : null,
				dateCreated: $song->createdAt > 0 ? $song->createdAt : null,
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
			);
		}

		return new LibraryData(
			songs: $resultSongs,
			composers: $composers,
			books: $books,
			collections: $collections,
			artists: $artists,
			genres: $genres,
			keys: $keys,
			customGroups: $customGroups,
			years: $years,
			signatures: $signatures,
			sourceTypes: $sourceTypes,
			instruments: $instruments,
		);
	}
}
