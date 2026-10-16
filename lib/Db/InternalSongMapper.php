<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCA\SheetMusic\Service\MobileSheetsPathResolver;
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
		private ?InternalMiscMapper $miscMapper = null,
		private ?IRootFolder $rootFolder = null,
		private ?MobileSheetsPathResolver $pathResolver = null,
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
		foreach ($data['songs'] as $song) {
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

	/**
	 * @return list<InternalSong>
	 */
	public function findByLibrary(int $libraryId, bool $includeDeleted = false): array {
		return $this->findAllForLibrary($libraryId, $includeDeleted);
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
			$qb->delete(self::TABLE_NAME)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->update(self::TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		}

		return $qb->executeStatement() > 0;
	}

	public function restore(int $id): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

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

		// 3. Delete dropped song
		$this->delete($droppedId);
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
	public function findLibraryData(int $libraryId, ?IUser $user = null): array {
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

			match ($type) {
				'composer' => $composers[] = new MobileSheetsComposer($eid, $e->name),
				'book' => $books[] = new MobileSheetsBook($eid, $e->name),
				'collection' => $collections[] = new MobileSheetsCollection($eid, $e->name),
				'artist' => $artists[] = new MobileSheetsArtist($eid, $e->name),
				'genre' => $genres[] = new MobileSheetsGenre($eid, $e->name),
				'key' => $keys[] = new MobileSheetsKey($eid, $e->name),
				'customgroup' => $customGroups[] = new MobileSheetsCustomGroup($eid, $e->name),
				'year' => $years[] = new MobileSheetsYear($eid, (int)$e->name),
				'signature' => $signatures[] = new MobileSheetsSignature($eid, $e->name),
				'sourcetype' => $sourceTypes[] = new MobileSheetsSourceType($eid, $e->name),
				'instrument' => $instruments[] = new MobileSheetsCustomGroup($eid, $e->name),
				default => null,
			};
		}

		$songs = $this->findAllForLibrary($libraryId);

		$songEntities = [];
		if ($this->entityMapper !== null) {
			$links = $this->entityMapper->findAllSongEntityLinksForLibrary($libraryId);
			foreach ($links as $link) {
				$normType = InternalEntityMapper::normalizeType($link['type']);
				$songEntities[$link['song_id']][$normType][] = $link['entity_id'];
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
					$fileNodePaths[(int)$r['fileid']] = '/remote.php/dav/files/' . $uid . '/' . ltrim($p, '/');
				}
				$resCache->closeCursor();
			} catch (\Throwable) {
				// Fall through
			}
		}

		$songFiles = [];
		foreach ($files as $file) {
			$path = $fileNodePaths[$file->fileId] ?? ('/remote.php/dav/files/' . ($user !== null ? $user->getUID() : '') . '/file_' . $file->fileId . '.pdf');
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
						$e = $entitiesById[$id];
						$res[] = match ($type) {
							'composer' => new MobileSheetsComposer($e->id, $e->name),
							'book' => new MobileSheetsBook($e->id, $e->name),
							'collection' => new MobileSheetsCollection($e->id, $e->name),
							'artist' => new MobileSheetsArtist($e->id, $e->name),
							'genre' => new MobileSheetsGenre($e->id, $e->name),
							'key' => new MobileSheetsKey($e->id, $e->name),
							'customgroup' => new MobileSheetsCustomGroup($e->id, $e->name),
							'year' => new MobileSheetsYear($e->id, (int)$e->name),
							'signature' => new MobileSheetsSignature($e->id, $e->name),
							'sourcetype' => new MobileSheetsSourceType($e->id, $e->name),
							'instrument' => new MobileSheetsCustomGroup($e->id, $e->name),
							default => new MobileSheetsCustomGroup($e->id, $e->name),
						};
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

		return [
			'songs' => $resultSongs,
			'composers' => $composers,
			'books' => $books,
			'collections' => $collections,
			'artists' => $artists,
			'genres' => $genres,
			'keys' => $keys,
			'customGroups' => $customGroups,
			'years' => $years,
			'signatures' => $signatures,
			'sourceTypes' => $sourceTypes,
			'instruments' => $instruments,
		];
	}
}
