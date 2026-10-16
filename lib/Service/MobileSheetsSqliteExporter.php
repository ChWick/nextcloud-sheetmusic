<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use Psr\Log\LoggerInterface;

class MobileSheetsSqliteExporter {
	/**
	 * Mapping of internal entity types to MobileSheets table specifications.
	 *
	 * @var array<string, array{table: string, nameCol: string, linkTable: string, linkCol: string, hasSort: bool}>
	 */
	public const ENTITY_TABLE_CONFIG = [
		'composer' => [
			'table' => 'Composer',
			'nameCol' => 'Name',
			'linkTable' => 'ComposerSongs',
			'linkCol' => 'ComposerId',
			'hasSort' => true,
		],
		'artist' => [
			'table' => 'Artists',
			'nameCol' => 'Name',
			'linkTable' => 'ArtistsSongs',
			'linkCol' => 'ArtistId',
			'hasSort' => false,
		],
		'book' => [
			'table' => 'Books',
			'nameCol' => 'Title',
			'linkTable' => 'BookSongs',
			'linkCol' => 'BookId',
			'hasSort' => true,
		],
		'collection' => [
			'table' => 'Collections',
			'nameCol' => 'Name',
			'linkTable' => 'CollectionSong',
			'linkCol' => 'CollectionId',
			'hasSort' => true,
		],
		'genre' => [
			'table' => 'Genres',
			'nameCol' => 'Type',
			'linkTable' => 'GenresSongs',
			'linkCol' => 'GenreId',
			'hasSort' => false,
		],
		'key' => [
			'table' => 'Keys',
			'nameCol' => 'Name',
			'linkTable' => 'KeySongs',
			'linkCol' => 'KeyId',
			'hasSort' => false,
		],
		'customgroup' => [
			'table' => 'CustomGroup',
			'nameCol' => 'Name',
			'linkTable' => 'CustomGroupSongs',
			'linkCol' => 'GroupId',
			'hasSort' => false,
		],
		'year' => [
			'table' => 'Years',
			'nameCol' => 'Year',
			'linkTable' => 'YearsSongs',
			'linkCol' => 'YearId',
			'hasSort' => false,
		],
		'signature' => [
			'table' => 'Signature',
			'nameCol' => 'Name',
			'linkTable' => 'SignatureSongs',
			'linkCol' => 'SignatureId',
			'hasSort' => false,
		],
		'sourcetype' => [
			'table' => 'SourceTypes',
			'nameCol' => 'Type',
			'linkTable' => 'SourceTypeSongs',
			'linkCol' => 'SourceTypeId',
			'hasSort' => false,
		],
		'instrument' => [
			'table' => 'CustomGroup',
			'nameCol' => 'Name',
			'linkTable' => 'CustomGroupSongs',
			'linkCol' => 'GroupId',
			'hasSort' => false,
		],
	];

	public function __construct(
		private InternalSongMapper $songMapper,
		private InternalEntityMapper $entityMapper,
		private InternalSongFileMapper $songFileMapper,
		private InternalSongAttributeMapper $attributeMapper,
		private InternalMiscMapper $miscMapper,
		private LibraryMapper $libraryMapper,
		private MobileSheetsPathResolver $pathResolver,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Export the entire library state to a MobileSheets-compatible SQLite database file.
	 */
	public function exportToFile(InternalLibrary|array|int $library, string $targetSqlitePath): void {
		$origPrecision = ini_get('precision');
		ini_set('precision', '-1');

		try {
			$lib = $this->resolveLibrary($library);
			if ($lib->id === null) {
				throw new \InvalidArgumentException('Library must have an ID to export');
			}

			$directory = dirname($targetSqlitePath);
			if (!is_dir($directory)) {
				mkdir($directory, 0777, true);
			}
			if (file_exists($targetSqlitePath)) {
				@unlink($targetSqlitePath);
			}

			$db = new PDO('sqlite:' . $targetSqlitePath, options: [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);

			$db->exec('PRAGMA synchronous = OFF');
			$db->exec('PRAGMA journal_mode = MEMORY');

			$this->initializeSchema($db, $lib->id);
			$this->applyUserVersion($db, $lib->id);

			$db->beginTransaction();
			try {
				$user = $this->userManager->get($lib->userId);

				$this->exportMiscData($db, $lib->id);
				$this->exportEntities($db, $lib->id);
				$this->exportSongs($db, $lib->id);
				$this->exportSongEntityLinks($db, $lib->id);
				$this->exportFiles($db, $lib, $user);

				$db->commit();
			} catch (\Throwable $e) {
				if ($db->inTransaction()) {
					$db->rollBack();
				}
				$this->logger->error('Failed to export MobileSheets SQLite database: ' . $e->getMessage(), [
					'exception' => $e,
					'libraryId' => $lib->id,
				]);
				throw $e;
			}
		} finally {
			if ($origPrecision !== false) {
				ini_set('precision', $origPrecision);
			}
		}
	}

	/**
	 * Generate the dynamic content for mobilesheets_hashcodes.txt.
	 */
	public function generateHashcodes(InternalLibrary|array|int $library): string {
		$lib = $this->resolveLibrary($library);
		if ($lib->id === null) {
			return '';
		}

		$user = $this->userManager->get($lib->userId);
		$files = $this->songFileMapper->findAllForLibrary($lib->id, includeDeleted: false);

		$lines = [];
		foreach ($files as $file) {
			$webdavPath = $this->resolveWebdavPathForFile($file, $lib, $user);
			if ($webdavPath === '') {
				continue;
			}

			$hash = $file->fileHash ?? 0;
			$modifiedAtMs = $file->updatedAt * 1000;
			$size = $file->fileSize ?? 0;

			$lines[] = $webdavPath;
			$lines[] = (string)$hash;
			$lines[] = (string)$modifiedAtMs;
			$lines[] = (string)$size;
		}

		return implode("\n", $lines);
	}

	private function resolveLibrary(InternalLibrary|array|int $library): InternalLibrary {
		if ($library instanceof InternalLibrary) {
			return $library;
		}

		if (is_int($library)) {
			$model = $this->libraryMapper->findModelById($library);
			if ($model === null) {
				throw new \InvalidArgumentException('Library not found: ' . $library);
			}
			return $model;
		}

		return InternalLibrary::fromRow($library);
	}

	private function applyUserVersion(PDO $db, int $libraryId): void {
		$metadataRows = $this->miscMapper->getMiscDataForTable($libraryId, 'mobilesheets', '__metadata');
		$userVersion = null;
		foreach ($metadataRows as $row) {
			if (isset($row['user_version']) && is_numeric($row['user_version'])) {
				$userVersion = (int)$row['user_version'];
				break;
			}
		}

		// Fallback to standard MobileSheets schema version 63 if not explicitly specified
		$userVersion ??= 63;
		$db->exec('PRAGMA user_version = ' . $userVersion);
	}

	private function initializeSchema(PDO $db, int $libraryId): void {
		$miscSchemas = $this->miscMapper->getMiscSchemas($libraryId, 'mobilesheets');
		$miscSchemaKeysLower = array_change_key_case($miscSchemas, CASE_LOWER);

		if (isset($miscSchemaKeysLower['songs'])) {
			foreach ($miscSchemas as $tableName => $createSql) {
				if (strtolower($tableName) === '_nc_sync_metadata' && !isset($miscSchemaKeysLower['_nc_sync_metadata'])) {
					continue;
				}
				$db->exec($createSql);
			}
			if (isset($miscSchemaKeysLower['_nc_sync_metadata'])) {
				$db->exec('CREATE INDEX IF NOT EXISTS idx_nc_sync_client ON _nc_sync_metadata (entity_type, client_id)');
			}
			return;
		}

		$statements = [
			'Songs' => 'CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')',
			'Files' => 'CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)',
			'Collections' => 'CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CollectionSong' => 'CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)',
			'Composer' => 'CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'ComposerSongs' => 'CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)',
			'Books' => 'CREATE TABLE Books(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'BookSongs' => 'CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY, BookId INTEGER, SongId INTEGER)',
			'Artists' => 'CREATE TABLE Artists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'ArtistsSongs' => 'CREATE TABLE ArtistsSongs(Id INTEGER PRIMARY KEY, ArtistId INTEGER, SongId INTEGER)',
			'Genres' => 'CREATE TABLE Genres(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'GenresSongs' => 'CREATE TABLE GenresSongs(Id INTEGER PRIMARY KEY, GenreId INTEGER, SongId INTEGER)',
			'Keys' => 'CREATE TABLE Keys(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'KeySongs' => 'CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)',
			'CustomGroup' => 'CREATE TABLE CustomGroup(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CustomGroupSongs' => 'CREATE TABLE CustomGroupSongs(Id INTEGER PRIMARY KEY, GroupId INTEGER, SongId INTEGER)',
			'Years' => 'CREATE TABLE Years(Id INTEGER PRIMARY KEY, Year INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'YearsSongs' => 'CREATE TABLE YearsSongs(Id INTEGER PRIMARY KEY, YearId INTEGER, SongId INTEGER)',
			'Signature' => 'CREATE TABLE Signature(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'SignatureSongs' => 'CREATE TABLE SignatureSongs(Id INTEGER PRIMARY KEY, SignatureId INTEGER, SongId INTEGER)',
			'SourceTypes' => 'CREATE TABLE SourceTypes(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'SourceTypeSongs' => 'CREATE TABLE SourceTypeSongs(Id INTEGER PRIMARY KEY, SourceTypeId INTEGER, SongId INTEGER)',
			'Bookmarks' => 'CREATE TABLE Bookmarks(Id INTEGER PRIMARY KEY, SongId INTEGER, Name VARCHAR(255), PageNum INTEGER, ShowInLibrary INTEGER DEFAULT 0, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'AudioFiles' => 'CREATE TABLE AudioFiles(Id INTEGER PRIMARY KEY, SongId INTEGER, Title VARCHAR(255), File VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'SongNotes' => 'CREATE TABLE SongNotes(Id INTEGER PRIMARY KEY, SongId INTEGER, Note TEXT, Title VARCHAR(255) DEFAULT \'\', DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'MetronomeSettings' => 'CREATE TABLE MetronomeSettings(Id INTEGER PRIMARY KEY, SongId INTEGER, Bpm INTEGER)',
			'_nc_sync_metadata' => 'CREATE TABLE _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))',
		];

		foreach ($statements as $tableName => $sql) {
			$lowerName = strtolower($tableName);
			if (isset($miscSchemaKeysLower[$lowerName])) {
				$sql = $miscSchemaKeysLower[$lowerName];
			}
			$db->exec($sql);
		}

		$db->exec('CREATE INDEX IF NOT EXISTS idx_nc_sync_client ON _nc_sync_metadata (entity_type, client_id)');

		// Any remaining misc schemas not yet created
		foreach ($miscSchemas as $tableName => $createSql) {
			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
			if ($check === false || $check->fetch() === false) {
				try {
					$db->exec($createSql);
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to execute misc schema DDL for table: ' . $tableName, ['exception' => $e]);
				}
			}
		}
	}

	private function exportEntities(PDO $db, int $libraryId): void {
		$entities = $this->entityMapper->findAllForLibrary($libraryId, includeDeleted: false);

		// Determine highest clientId per table to assign new client IDs safely
		$usedIds = [];
		$maxId = 0;
		foreach ($entities as $e) {
			if ($e->clientId !== null && $e->clientId > 0) {
				$usedIds[$e->clientId] = true;
				if ($e->clientId > $maxId) {
					$maxId = $e->clientId;
				}
			}
		}

		$nextId = $maxId + 1;

		$hasSyncMeta = false;
		$checkMeta = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_nc_sync_metadata'");
		if ($checkMeta !== false && $checkMeta->fetch() !== false) {
			$hasSyncMeta = true;
		}

		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		foreach ($entities as $entity) {
			$config = self::ENTITY_TABLE_CONFIG[$entity->type] ?? self::ENTITY_TABLE_CONFIG['customgroup'];

			$tableName = $config['table'];
			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
			if ($check === false || $check->fetch() === false) {
				if ($tableName === 'Keys') {
					$alt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Key'");
					if ($alt !== false && $alt->fetch() !== false) {
						$tableName = 'Key';
					}
				} elseif ($tableName === 'SourceTypes') {
					$alt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='SourceType'");
					if ($alt !== false && $alt->fetch() !== false) {
						$tableName = 'SourceType';
					}
				}
			}

			$clientId = $entity->clientId;
			if ($clientId === null || $clientId <= 0) {
				while (isset($usedIds[$nextId])) {
					$nextId++;
				}
				$clientId = $nextId++;
				$usedIds[$clientId] = true;

				$entity->clientId = $clientId;
				$this->entityMapper->update($entity);
			}

			$rowExists = false;
			$checkRow = $db->query(sprintf('SELECT 1 FROM "%s" WHERE Id = %d', str_replace('"', '""', $tableName), (int)$clientId));
			if ($checkRow !== false && $checkRow->fetch() !== false) {
				$rowExists = true;
			}

			if (!$rowExists) {
				$colStmt = $db->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $tableName)));
				$existingCols = [];
				if ($colStmt !== false) {
					while ($colInfo = $colStmt->fetch()) {
						if (isset($colInfo['name'])) {
							$existingCols[strtolower((string)$colInfo['name'])] = (string)$colInfo['name'];
						}
					}
				}

				$nameCol = $config['nameCol'];
				if (!isset($existingCols[strtolower($nameCol)])) {
					if (isset($existingCols['name'])) {
						$nameCol = $existingCols['name'];
					} elseif (isset($existingCols['title'])) {
						$nameCol = $existingCols['title'];
					} elseif (isset($existingCols['type'])) {
						$nameCol = $existingCols['type'];
					} elseif (isset($existingCols['year'])) {
						$nameCol = $existingCols['year'];
					}
				}

				$val = (strtolower($nameCol) === 'year') ? (int)$entity->name : $entity->name;
				$hasSortCols = isset($existingCols['sortby']) && isset($existingCols['ascending']);
				$dateCreatedCol = isset($existingCols['datecreated']) ? $existingCols['datecreated'] : (isset($existingCols['creationdate']) ? $existingCols['creationdate'] : null);
				$lastModCol = isset($existingCols['lastmodified']) ? $existingCols['lastmodified'] : null;

				$cols = ['Id', $nameCol];
				$vals = [$clientId, $val];

				if ($hasSortCols) {
					$cols[] = $existingCols['sortby'];
					$vals[] = $entity->sortBy;
					$cols[] = $existingCols['ascending'];
					$vals[] = $entity->ascending;
				}
				if ($dateCreatedCol !== null) {
					$cols[] = $dateCreatedCol;
					$vals[] = $entity->createdAt * 1000;
				}
				if ($lastModCol !== null) {
					$cols[] = $lastModCol;
					$vals[] = $entity->updatedAt * 1000;
				}

				$escaped = array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $cols);
				$placeholders = array_fill(0, count($cols), '?');
				$sql = sprintf('INSERT INTO "%s" (%s) VALUES (%s)', str_replace('"', '""', $tableName), implode(', ', $escaped), implode(', ', $placeholders));
				$stmt = $db->prepare($sql);
				$stmt->execute($vals);
			}

			if ($metaStmt !== null) {
				$metaStmt->execute([
					':type' => 'entity:' . $entity->type,
					':uuid' => $entity->uuid,
					':client_id' => $clientId,
					':updated_at' => $entity->updatedAt,
					':extra_json' => json_encode(['original_type' => $entity->type, 'name' => $entity->name], JSON_THROW_ON_ERROR),
				]);
			}
		}
	}

	private function exportSongs(PDO $db, int $libraryId): void {
		$checkSongs = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Songs'");
		if ($checkSongs === false || $checkSongs->fetch() === false) {
			return;
		}

		$hasNotesTable = false;
		$checkNotes = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='SongNotes'");
		if ($checkNotes !== false && $checkNotes->fetch() !== false) {
			$hasNotesTable = true;
		}

		$hasSyncMeta = false;
		$checkMeta = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_nc_sync_metadata'");
		if ($checkMeta !== false && $checkMeta->fetch() !== false) {
			$hasSyncMeta = true;
		}

		$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: false);
		$attributesBySong = $this->attributeMapper->findAllForLibrary($libraryId);

		$usedIds = [];
		$maxId = 0;
		foreach ($songs as $s) {
			if ($s->clientId !== null && $s->clientId > 0) {
				$usedIds[$s->clientId] = true;
				if ($s->clientId > $maxId) {
					$maxId = $s->clientId;
				}
			}
		}

		$nextId = $maxId + 1;

		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		$colStmt = $db->query('PRAGMA table_info("Songs")');
		$existingCols = [];
		if ($colStmt !== false) {
			while ($c = $colStmt->fetch()) {
				$existingCols[strtolower((string)$c['name'])] = (string)$c['name'];
			}
		}

		foreach ($songs as $song) {
			$clientId = $song->clientId;
			if ($clientId === null || $clientId <= 0) {
				while (isset($usedIds[$nextId])) {
					$nextId++;
				}
				$clientId = $nextId++;
				$usedIds[$clientId] = true;

				$song->clientId = $clientId;
				$this->songMapper->update($song);
			}

			$rowExists = false;
			$checkRow = $db->query(sprintf('SELECT 1 FROM Songs WHERE Id = %d', (int)$clientId));
			if ($checkRow !== false && $checkRow->fetch() !== false) {
				$rowExists = true;
			}

			if (!$rowExists) {
				$attrs = $attributesBySong[$song->id] ?? [];
				$custom = $attrs['custom'] ?? '';
				$custom2 = $attrs['custom2'] ?? '';

				$candidateValues = [
					'id' => $clientId,
					'title' => $song->title,
					'sorttitle' => $song->sortTitle,
					'stars' => $song->rating,
					'difficulty' => $song->difficulty,
					'duration' => $song->duration,
					'keywords' => $song->keywords,
					'custom' => $custom,
					'custom2' => $custom2,
					'songid' => $clientId,
					'datecreated' => $song->createdAt * 1000,
					'creationdate' => $song->createdAt * 1000,
					'lastmodified' => $song->updatedAt * 1000,
					'opus' => $song->opus ?? '',
					'catalognumber' => $song->catalogNumber ?? '',
					'songnumber' => $song->songNumber ?? '',
				];

				$insertCols = [];
				$insertVals = [];
				foreach ($candidateValues as $lowerKey => $val) {
					if (isset($existingCols[$lowerKey])) {
						$insertCols[] = '"' . $existingCols[$lowerKey] . '"';
						$insertVals[] = $val;
					}
				}

				if ($insertCols !== []) {
					$placeholders = array_fill(0, count($insertCols), '?');
					$sql = sprintf(
						'INSERT INTO Songs (%s) VALUES (%s)',
						implode(', ', $insertCols),
						implode(', ', $placeholders),
					);
					$stmt = $db->prepare($sql);
					$stmt->execute($insertVals);
				}
			}

			if ($hasNotesTable && $song->notes !== '') {
				$noteExists = false;
				$checkNote = $db->query(sprintf('SELECT 1 FROM SongNotes WHERE SongId = %d', (int)$clientId));
				if ($checkNote !== false && $checkNote->fetch() !== false) {
					$noteExists = true;
				}

				if (!$noteExists) {
					$noteStmt = $db->prepare('INSERT INTO SongNotes (SongId, Note, Title, DateCreated, LastModified) VALUES (?, ?, ?, ?, ?)');
					$noteStmt->execute([
						$clientId,
						$song->notes,
						'',
						$song->createdAt * 1000,
						$song->updatedAt * 1000,
					]);
				}
			}

			if ($metaStmt !== null) {
				$extra = [
					'opus' => $song->opus,
					'catalog_number' => $song->catalogNumber,
					'song_number' => $song->songNumber,
				];

				$metaStmt->execute([
					':type' => 'song',
					':uuid' => $song->uuid,
					':client_id' => $clientId,
					':updated_at' => $song->updatedAt,
					':extra_json' => json_encode($extra, JSON_THROW_ON_ERROR),
				]);
			}
		}
	}

	private function exportSongEntityLinks(PDO $db, int $libraryId): void {
		$links = $this->entityMapper->findAllSongEntityLinksForLibrary($libraryId);

		$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: false);
		$songClientMap = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songClientMap[$s->id] = $s->clientId;
			}
		}

		$entities = $this->entityMapper->findAllForLibrary($libraryId, includeDeleted: false);
		$entityClientMap = [];
		foreach ($entities as $e) {
			if ($e->clientId !== null) {
				$entityClientMap[$e->id] = $e->clientId;
			}
		}

		foreach ($links as $link) {
			$songId = $link['song_id'];
			$entityId = $link['entity_id'];
			$type = $link['type'];

			$songClientId = $songClientMap[$songId] ?? null;
			$entityClientId = $entityClientMap[$entityId] ?? null;

			if ($songClientId === null || $entityClientId === null) {
				continue;
			}

			$config = self::ENTITY_TABLE_CONFIG[$type] ?? self::ENTITY_TABLE_CONFIG['customgroup'];
			$linkTable = $config['linkTable'];
			$linkCol = $config['linkCol'];

			$checkLinkTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $linkTable) . "'");
			if ($checkLinkTable === false || $checkLinkTable->fetch() === false) {
				continue;
			}

			$checkExisting = $db->prepare(sprintf('SELECT 1 FROM "%s" WHERE "%s" = ? AND SongId = ?', str_replace('"', '""', $linkTable), str_replace('"', '""', $linkCol)));
			$checkExisting->execute([$entityClientId, $songClientId]);
			if ($checkExisting->fetch() !== false) {
				continue;
			}

			$sql = sprintf(
				'INSERT INTO "%s" ("%s", SongId) VALUES (?, ?)',
				str_replace('"', '""', $linkTable),
				str_replace('"', '""', $linkCol),
			);
			$stmt = $db->prepare($sql);
			$stmt->execute([$entityClientId, $songClientId]);
		}
	}

	private function exportFiles(PDO $db, InternalLibrary $library, ?IUser $user): void {
		$checkFiles = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Files'");
		if ($checkFiles === false || $checkFiles->fetch() === false) {
			return;
		}

		$hasSyncMeta = false;
		$checkMeta = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_nc_sync_metadata'");
		if ($checkMeta !== false && $checkMeta->fetch() !== false) {
			$hasSyncMeta = true;
		}

		$files = $this->songFileMapper->findAllForLibrary($library->id, includeDeleted: false);

		$songs = $this->songMapper->findByLibrary($library->id, includeDeleted: false);
		$songClientMap = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songClientMap[$s->id] = $s->clientId;
			}
		}

		$usedIds = [];
		$maxId = 0;
		foreach ($files as $f) {
			if ($f->clientId !== null && $f->clientId > 0) {
				$usedIds[$f->clientId] = true;
				if ($f->clientId > $maxId) {
					$maxId = $f->clientId;
				}
			}
		}

		$nextId = $maxId + 1;

		$colStmt = $db->query('PRAGMA table_info("Files")');
		$existingCols = [];
		if ($colStmt !== false) {
			while ($c = $colStmt->fetch()) {
				$existingCols[strtolower((string)$c['name'])] = (string)$c['name'];
			}
		}

		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		foreach ($files as $file) {
			$songClientId = $songClientMap[$file->songId] ?? null;
			if ($songClientId === null) {
				continue;
			}

			$clientId = $file->clientId;
			if ($clientId === null || $clientId <= 0) {
				while (isset($usedIds[$nextId])) {
					$nextId++;
				}
				$clientId = $nextId++;
				$usedIds[$clientId] = true;

				$file->clientId = $clientId;
				$this->songFileMapper->update($file);
			}

			$checkRow = $db->query(sprintf('SELECT 1 FROM Files WHERE Id = %d', (int)$clientId));
			if ($checkRow === false || $checkRow->fetch() === false) {
				$webdavPath = $this->resolveWebdavPathForFile($file, $library, $user);

				$hash = $file->fileHash ?? 0;
				$size = $file->fileSize ?? 0;
				$pageCount = $file->pageCount ?? 1;

				$candidateValues = [
					'id' => $clientId,
					'songid' => $songClientId,
					'path' => $webdavPath,
					'pageorder' => $file->pageOrder,
					'filesize' => $size,
					'lastmodified' => $file->updatedAt * 1000,
					'source' => 1,
					'type' => $file->type,
					'password' => '',
					'sourcefilepagecount' => $pageCount,
					'filehash' => $hash,
					'width' => -1,
					'height' => -1,
				];

				$insertCols = [];
				$insertVals = [];
				foreach ($candidateValues as $lowerKey => $val) {
					if (isset($existingCols[$lowerKey])) {
						$insertCols[] = '"' . $existingCols[$lowerKey] . '"';
						$insertVals[] = $val;
					}
				}

				if ($insertCols !== []) {
					$placeholders = array_fill(0, count($insertCols), '?');
					$sql = sprintf('INSERT INTO Files (%s) VALUES (%s)', implode(', ', $insertCols), implode(', ', $placeholders));
					$stmt = $db->prepare($sql);
					$stmt->execute($insertVals);
				}
			}

			if ($metaStmt !== null) {
				$metaStmt->execute([
					':type' => 'file',
					':uuid' => $file->uuid,
					':client_id' => $clientId,
					':updated_at' => $file->updatedAt,
					':extra_json' => json_encode(['file_id' => $file->fileId], JSON_THROW_ON_ERROR),
				]);
			}
		}
	}

	private function exportMiscData(PDO $db, int $libraryId): void {
		$miscSchemas = $this->miscMapper->getMiscSchemas($libraryId, 'mobilesheets');
		if ($miscSchemas === []) {
			return;
		}

		$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: true);
		$songClientByUuid = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songClientByUuid[$s->uuid] = $s->clientId;
			}
		}

		foreach (array_keys($miscSchemas) as $tableName) {
			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
			if ($check === false || $check->fetch() === false) {
				continue;
			}

			// Query table column info to ensure we only insert existing columns
			$colStmt = $db->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $tableName)));
			$existingColumns = [];
			if ($colStmt !== false) {
				while ($colInfo = $colStmt->fetch()) {
					if (isset($colInfo['name'])) {
						$existingColumns[strtolower((string)$colInfo['name'])] = (string)$colInfo['name'];
					}
				}
			}

			$rows = $this->miscMapper->getMiscDataForTable($libraryId, 'mobilesheets', $tableName, includeDeleted: false);
			foreach ($rows as $row) {
				if (!is_array($row) || $row === []) {
					continue;
				}

				if ($existingColumns !== []) {
					$filteredRow = [];
					foreach ($row as $colKey => $val) {
						$lowerKey = strtolower((string)$colKey);
						if (isset($existingColumns[$lowerKey])) {
							$filteredRow[$existingColumns[$lowerKey]] = $val;
						}
					}
					$row = $filteredRow;
				}

				if ($row === []) {
					continue;
				}

				$cols = array_keys($row);
				$escapedCols = array_map(fn ($c) => '"' . str_replace('"', '""', (string)$c) . '"', $cols);
				$placeholders = array_fill(0, count($cols), '?');

				$sql = sprintf(
					'INSERT OR REPLACE INTO "%s" (%s) VALUES (%s)',
					$tableName,
					implode(', ', $escapedCols),
					implode(', ', $placeholders),
				);

				try {
					$stmt = $db->prepare($sql);
					$stmt->execute(array_values($row));
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to insert misc row into table: ' . $tableName, ['exception' => $e]);
				}
			}
		}
	}

	private function resolveWebdavPathForFile(InternalSongFile $file, InternalLibrary $library, ?IUser $user): string {
		if ($user !== null) {
			try {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$nodes = $userFolder->getById($file->fileId);
				if ($nodes !== []) {
					$nodePath = $nodes[0]->getPath();
					$msPath = $this->pathResolver->fromInternal($nodePath, $user);
					if ($msPath !== null) {
						return $msPath->webdav;
					}
				}
			} catch (\Throwable) {
				// Fall through
			}

			try {
				$rootNodes = $this->rootFolder->getById($file->fileId);
				if ($rootNodes !== []) {
					$nodePath = $rootNodes[0]->getPath();
					$msPath = $this->pathResolver->fromInternal($nodePath, $user);
					if ($msPath !== null) {
						return $msPath->webdav;
					}
				}
			} catch (\Throwable) {
				// Fall through
			}
		}

		// Fallback
		$base = rtrim($library->path, '/');
		return sprintf('/remote.php/dav/files/%s/%s/file_%d.pdf', $library->userId, ltrim($base, '/'), $file->fileId);
	}
}
