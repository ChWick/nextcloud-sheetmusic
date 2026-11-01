<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace TestSupport;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongBookmarkMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PDO;
use Psr\Log\NullLogger;

/**
 * Shared test trait providing real, mock-free Nextcloud database connections,
 * mappers, MobileSheets importer & exporter, and automated teardown cleanup.
 */
trait RealMobileSheetsTestTrait {
	protected IDBConnection $dbConn;
	protected InternalSongMapper $songMapper;
	protected InternalEntityMapper $entityMapper;
	protected InternalSongFileMapper $songFileMapper;
	protected InternalSongAttributeMapper $attributeMapper;
	protected InternalSongBookmarkMapper $bookmarkMapper;
	protected InternalMiscMapper $miscMapper;
	protected LibraryMapper $libraryMapper;
	protected IRootFolder $rootFolder;
	protected IUserManager $userManager;
	protected MobileSheetsPathResolver $pathResolver;
	protected MobileSheetsSqliteImporter $importer;
	protected MobileSheetsSqliteExporter $exporter;

	/** @var list<int> */
	protected array $createdLibraryIds = [];

	/** @var list<string> */
	protected array $tempFiles = [];

	/** @var list<Folder|File> */
	protected array $createdUserNodes = [];

	protected function setUpRealMobileSheetsEnvironment(): void {
		$this->dbConn = \OC::$server->get(IDBConnection::class);
		$this->entityMapper = new InternalEntityMapper($this->dbConn);
		$this->songFileMapper = new InternalSongFileMapper($this->dbConn);
		$this->attributeMapper = new InternalSongAttributeMapper($this->dbConn);
		$this->bookmarkMapper = new InternalSongBookmarkMapper($this->dbConn);
		$this->miscMapper = new InternalMiscMapper($this->dbConn);
		$this->libraryMapper = new LibraryMapper($this->dbConn);
		$this->rootFolder = \OC::$server->get(IRootFolder::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$logger = new NullLogger();
		$this->pathResolver = new MobileSheetsPathResolver($this->rootFolder);
		$this->songMapper = new InternalSongMapper(
			$this->dbConn,
			$this->entityMapper,
			$this->songFileMapper,
			$this->rootFolder,
			$this->bookmarkMapper,
		);

		$this->importer = new MobileSheetsSqliteImporter(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$this->libraryMapper,
			$this->pathResolver,
			$this->rootFolder,
			$this->userManager,
			$logger,
		);

		$this->exporter = new MobileSheetsSqliteExporter(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$this->libraryMapper,
			$this->pathResolver,
			$this->rootFolder,
			$this->userManager,
			$logger,
			null,
			$this->bookmarkMapper,
		);
	}

	protected function tearDownRealMobileSheetsEnvironment(): void {
		foreach ($this->createdLibraryIds as $libId) {
			$this->cleanupLibraryData($libId);
		}
		$this->createdLibraryIds = [];

		foreach ($this->tempFiles as $file) {
			if (file_exists($file)) {
				@unlink($file);
			}
		}
		$this->tempFiles = [];

		foreach (array_reverse($this->createdUserNodes) as $node) {
			try {
				if ($node instanceof Folder || $node instanceof File) {
					$node->delete();
				}
			} catch (\Throwable) {
				// Ignore if already deleted
			}
		}
		$this->createdUserNodes = [];
	}

	protected function cleanupLibraryData(int $libraryId): void {
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_entities WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$libraryId]);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_attributes WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$libraryId]);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_files WHERE song_id IN (SELECT id FROM *PREFIX*sheetmusic_songs WHERE library_id = ?)', [$libraryId]);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_files WHERE song_id NOT IN (SELECT id FROM *PREFIX*sheetmusic_songs)');
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_song_history WHERE library_id = ?', [$libraryId]);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_songs WHERE library_id = ?', [$libraryId]);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_entities WHERE library_id = ?', [$libraryId]);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_misc_data WHERE library_id = ?', [$libraryId]);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_misc_schema WHERE library_id = ?', [$libraryId]);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_libraries WHERE id = ?', [$libraryId]);
	}

	protected function createTestLibrary(?string $path = null, ?string $userId = null): InternalLibrary {
		$path ??= 'TestSync_' . bin2hex(random_bytes(4));
		$userId ??= 'admin';
		$this->libraryMapper->addForUser($userId, $path);
		$libs = $this->libraryMapper->findAllForUser($userId);
		$matching = array_filter($libs, fn (InternalLibrary $l) => $l->path === $path);
		$firstMatch = reset($matching);
		$lib = $firstMatch instanceof InternalLibrary ? $firstMatch : $libs[0];
		$this->createdLibraryIds[] = (int)$lib->id;
		return $lib;
	}

	protected function insertTestSong(int $libraryId, string $title): InternalSong {
		$song = new InternalSong(
			id: null,
			uuid: InternalEntity::generateUuid(),
			libraryId: $libraryId,
			title: $title,
			createdAt: time(),
			updatedAt: time(),
		);
		return $this->songMapper->insert($song);
	}

	protected function insertTestEntity(int $libraryId, string $type, string $name): InternalEntity {
		$entity = new InternalEntity(
			id: null,
			uuid: InternalEntity::generateUuid(),
			libraryId: $libraryId,
			type: InternalEntityMapper::normalizeType($type),
			name: $name,
			createdAt: time(),
			updatedAt: time(),
		);
		return $this->entityMapper->insert($entity);
	}

	protected function attachTestFileToSong(InternalSong $song, string $fileName = 'score.pdf', ?int $clientId = null): InternalSongFile {
		$lib = $this->libraryMapper->findById($song->libraryId);
		$userId = $lib ? $lib->userId : 'admin';
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$libPath = $lib ? trim($lib->path, '/') : 'Test';
		$folder = $userFolder->nodeExists($libPath) ? $userFolder->get($libPath) : $userFolder->newFolder($libPath);
		$uniqueName = bin2hex(random_bytes(3)) . '_' . $fileName;
		$fileNode = $folder->newFile($uniqueName);
		$fileNode->putContent("%PDF-1.4 Test Score\n");
		$this->createdUserNodes[] = $fileNode;

		$songFile = new InternalSongFile(
			uuid: 'sf_' . bin2hex(random_bytes(4)),
			songId: $song->id,
			fileId: (int)$fileNode->getId(),
			pageOrder: '1-1',
			pageCount: 1,
			fileSize: 1024,
			fileHash: 123456,
			type: 1,
			clientId: $clientId,
			createdAt: $song->createdAt,
			updatedAt: $song->updatedAt,
		);
		return $this->songFileMapper->insert($songFile);
	}

	protected function tempDbPath(): string {
		$path = tempnam(sys_get_temp_dir(), 'ms_sync_') . '.db';
		$this->tempFiles[] = $path;
		return $path;
	}

	protected function createEmptyMobileSheetsDb(?string $path = null): PDO {
		$path ??= $this->tempDbPath();
		$db = MobileSheetsTestDatabase::createEmpty($path);
		$db->exec('CREATE TABLE IF NOT EXISTS "Key"(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE IF NOT EXISTS SourceType(Id INTEGER PRIMARY KEY, Type VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		return $db;
	}

	protected function createLibraryIntegrityService(?LibraryService $libraryService = null): LibraryIntegrityService {
		$logger = new NullLogger();
		$libraryService ??= new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);

		return new LibraryIntegrityService(
			$libraryService,
			$fileManager,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$this->entityMapper,
			$this->songMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->attributeMapper,
			$this->miscMapper,
		);
	}
}
