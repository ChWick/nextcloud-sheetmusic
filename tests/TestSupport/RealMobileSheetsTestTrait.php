<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace TestSupport;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\MobileSheetsPathResolver;
use OCA\SheetMusic\Service\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Service\MobileSheetsSqliteImporter;
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
		$matching = array_filter($libs, fn($l) => $l['path'] === $path);
		$libId = (int)(reset($matching)['id'] ?? $libs[0]['id']);
		$lib = $this->libraryMapper->findModelById($libId);
		self::assertNotNull($lib);
		$this->createdLibraryIds[] = (int)$lib->id;
		return $lib;
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
}
