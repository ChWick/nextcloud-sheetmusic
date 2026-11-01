<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Tests\TestSupport;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongBookmarkMapper;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Listener\SheetMusicFileEventListener;
use OCA\SheetMusic\Service\EntityService;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\IntegrityIssue;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryReconciler;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBaselineSchema;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityConfig;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsHashCodesFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsTableHelper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;

/**
 * End-to-End test harness for MobileSheets <-> Nextcloud bidirectional synchronization.
 *
 * Uses zero mocks: wires real Nextcloud database mappers, real Nextcloud user folder
 * storage, real SQLite client/exported databases, real hashcodes files, and real
 * SheetMusic services (LibraryService, SongService, SongFileService, EntityService,
 * LibraryReconciler, LibraryIntegrityService, SheetMusicFileEventListener,
 * MobileSheetsSqliteImporter, MobileSheetsSqliteExporter).
 */
class MobileSheetsEndToEndSyncHarness {
	public IDBConnection $db;
	public IRootFolder $rootFolder;
	public IUserManager $userManager;
	public IConfig $config;
	public LoggerInterface $logger;

	public InternalSongMapper $songMapper;
	public InternalEntityMapper $entityMapper;
	public InternalSongFileMapper $songFileMapper;
	public InternalSongAttributeMapper $attributeMapper;
	public InternalSongBookmarkMapper $bookmarkMapper;
	public InternalMiscMapper $miscMapper;
	public LibraryMapper $libraryMapper;

	public MobileSheetsPathResolver $pathResolver;
	public MobileSheetsSqliteImporter $importer;
	public MobileSheetsSqliteExporter $exporter;

	public LibraryService $libraryService;
	public FileManager $fileManager;
	public SongFileService $songFileService;
	public SongService $songService;
	public EntityService $entityService;
	public LibraryReconciler $reconciler;
	public LibraryIntegrityService $integrityService;
	public SheetMusicFileEventListener $fileEventListener;

	public IUser $user;
	public string $userId;
	public int $libraryId = 0;
	public string $libraryFolderName = '';

	public string $tempDir;
	public string $clientDbPath;
	public string $clientHashCodesPath;
	public string $clientFilesDir;
	public string $ncExportedDbPath;

	private bool $clientDbDirty = false;
	private int $logicalClock = 0;

	public function __construct(?string $userId = null) {
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->rootFolder = \OC::$server->get(IRootFolder::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->config = \OC::$server->get(IConfig::class);
		$this->logger = \OC::$server->get(LoggerInterface::class);

		$this->entityMapper = new InternalEntityMapper($this->db);
		$this->songFileMapper = new InternalSongFileMapper($this->db);
		$this->attributeMapper = new InternalSongAttributeMapper($this->db);
		$this->bookmarkMapper = new InternalSongBookmarkMapper($this->db);
		$this->songMapper = new InternalSongMapper(
			$this->db,
			$this->entityMapper,
			$this->songFileMapper,
			$this->rootFolder,
			$this->bookmarkMapper,
		);
		$this->miscMapper = new InternalMiscMapper($this->db);
		$this->libraryMapper = new LibraryMapper($this->db);

		$this->pathResolver = new MobileSheetsPathResolver($this->rootFolder, $this->userManager);
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
			$this->logger,
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
			$this->logger,
			null,
			$this->bookmarkMapper,
		);

		$this->reconciler = new LibraryReconciler(
			$this->songMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->rootFolder,
			$this->userManager,
			$this->logger,
			$this->entityMapper,
		);
		$this->libraryService = new LibraryService(
			$this->libraryMapper,
			$this->rootFolder,
			$this->logger,
			$this->exporter,
			$this->importer,
			$this->reconciler,
		);
		$this->fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$this->logger,
			$this->libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);
		$this->songFileService = new SongFileService(
			$this->libraryService,
			$this->fileManager,
			$this->rootFolder,
			$this->logger,
			$this->songFileMapper,
			$this->songMapper,
			$this->libraryMapper,
		);
		$this->integrityService = new LibraryIntegrityService(
			$this->libraryService,
			$this->fileManager,
			$this->rootFolder,
			$this->pathResolver,
			$this->logger,
			$this->entityMapper,
			$this->songMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->attributeMapper,
			$this->miscMapper,
		);
		$this->songService = new SongService(
			$this->libraryService,
			$this->songFileService,
			$this->fileManager,
			$this->logger,
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->rootFolder,
			$this->integrityService,
			null,
			$this->bookmarkMapper,
		);
		$this->entityService = new EntityService(
			$this->libraryService,
			$this->logger,
			$this->entityMapper,
			$this->songMapper,
			$this->libraryMapper,
		);
		$this->fileEventListener = new SheetMusicFileEventListener(
			$this->libraryMapper,
			$this->songMapper,
			$this->songFileMapper,
			$this->reconciler,
			$this->rootFolder,
			$this->logger,
		);

		$this->userId = $userId ?? 'ms_e2e_sync_user';
		$user = $this->userManager->get($this->userId);
		if ($user === null) {
			$user = $this->userManager->createUser($this->userId, 'password123');
		}
		if (!($user instanceof IUser)) {
			throw new \RuntimeException('Failed to create test user: ' . $this->userId);
		}
		$this->user = $user;

		// Ensure userFolder is initialized
		$this->rootFolder->getUserFolder($this->userId);

		$this->tempDir = sys_get_temp_dir() . '/ms_e2e_' . bin2hex(random_bytes(6));
		mkdir($this->tempDir, 0777, true);
		$this->clientFilesDir = $this->tempDir . '/client_files';
		mkdir($this->clientFilesDir, 0777, true);
		$this->clientDbPath = $this->tempDir . '/client_mobilesheets.db';
		$this->clientHashCodesPath = $this->tempDir . '/client_mobilesheets_hashcodes.txt';
		$this->ncExportedDbPath = $this->tempDir . '/nc_exported_mobilesheets.db';

		$this->logicalClock = time();
	}

	/**
	 * Return a strictly increasing timestamp (in seconds) so LWW comparisons are deterministic
	 * without requiring real sleep() calls.
	 */
	public function nextTimestamp(): int {
		$now = time();
		if ($now > $this->logicalClock) {
			$this->logicalClock = $now;
		} else {
			$this->logicalClock++;
		}
		return $this->logicalClock;
	}

	/**
	 * Initialize an empty Nextcloud library and an empty MobileSheets client library.
	 */
	public function setupEmptyLibraries(
		string $folderName = 'MobileSheetsLib',
		string $libraryName = 'MobileSheets E2E Library',
		bool $autoPaths = false,
		bool $autoPdfMetadata = false,
	): int {
		$this->libraryFolderName = trim($folderName, '/');
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		if ($userFolder->nodeExists($this->libraryFolderName)) {
			$userFolder->get($this->libraryFolderName)->delete();
		}
		$userFolder->newFolder($this->libraryFolderName);

		$this->libraryService->addLibrary(
			$this->userId,
			'/' . $this->libraryFolderName,
			false,
		);
		$libraries = $this->libraryService->getLibraries($this->userId);
		Assert::assertNotEmpty($libraries, 'Library must be created for user');
		$this->libraryId = (int)$libraries[0]->id;

		$this->libraryService->updateSettings($this->userId, $this->libraryId, $autoPaths, $autoPdfMetadata);

		$this->initEmptyMobileSheetsClient();

		return $this->libraryId;
	}

	/**
	 * Initialize an empty MobileSheets SQLite database (with standard baseline schema)
	 * and empty hashcodes file on the client side.
	 */
	public function initEmptyMobileSheetsClient(): void {
		if (is_file($this->clientDbPath)) {
			@unlink($this->clientDbPath);
		}
		$pdo = $this->openSqlite($this->clientDbPath);
		foreach (MobileSheetsBaselineSchema::TABLES as $ddl) {
			$pdo->exec($ddl);
		}
		foreach (MobileSheetsBaselineSchema::INDEXES as $idxDdl) {
			$pdo->exec($idxDdl);
		}
		file_put_contents($this->clientHashCodesPath, '');
		$this->clientDbDirty = true;
	}

	public function getLibraryFolder(): Folder {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$node = $userFolder->get($this->libraryFolderName);
		if (!($node instanceof Folder)) {
			throw new \RuntimeException('Library folder not found: ' . $this->libraryFolderName);
		}
		return $node;
	}

	public function getLibraryModel(): InternalLibrary {
		$model = $this->libraryMapper->findModelById($this->libraryId);
		if ($model === null) {
			throw new \RuntimeException('Library model not found: ' . $this->libraryId);
		}
		return $model;
	}

	public function getNextcloudLibraryData(): LibraryData {
		return $this->songMapper->findLibraryData($this->libraryId, $this->user);
	}

	/**
	 * Generate a valid minimal PDF file with the given text and number of pages.
	 */
	public static function generateValidPdf(string $label = 'Test Sheet Music', int $pages = 1): string {
		$pageCount = max(1, $pages);
		$objects = [];
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		$kids = [];
		$nextObjId = 3;
		$pageEntries = [];
		for ($i = 1; $i <= $pageCount; $i++) {
			$pageObjId = $nextObjId++;
			$contentObjId = $nextObjId++;
			$kids[] = "{$pageObjId} 0 R";
			$pageEntries[] = [
				'pageId' => $pageObjId,
				'contentId' => $contentObjId,
				'text' => "{$label} - Page {$i}",
			];
		}

		$kidsStr = implode(' ', $kids);
		$objects[2] = "<< /Type /Pages /Kids [{$kidsStr}] /Count {$pageCount} >>";

		foreach ($pageEntries as $entry) {
			$safeText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $entry['text']);
			$stream = "BT /F1 12 Tf 72 720 Td ({$safeText}) Tj ET";
			$len = strlen($stream);
			$objects[$entry['pageId']] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$entry['contentId']} 0 R >>";
			$objects[$entry['contentId']] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
		}

		ksort($objects);
		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $id => $body) {
			$offsets[$id] = strlen($pdf);
			$pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
		}
		$xrefOffset = strlen($pdf);
		$totalObjs = count($objects) + 1;
		$pdf .= "xref\n0 {$totalObjs}\n";
		$pdf .= "0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}
		$pdf .= "trailer\n<< /Size {$totalObjs} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

		return $pdf;
	}

	// =========================================================================
	// MobileSheets Client Operations
	// =========================================================================

	/**
	 * Create a song on the MobileSheets client, optionally with a local PDF file and entity links.
	 *
	 * @param array{
	 *     sortTitle?: string,
	 *     stars?: int,
	 *     difficulty?: int,
	 *     duration?: int,
	 *     keywords?: string,
	 *     custom?: string,
	 *     custom2?: string,
	 *     opus?: string,
	 *     catalogNumber?: string,
	 *     songNumber?: string,
	 *     notes?: string,
	 *     pages?: int,
	 *     entities?: array<string, list<int>>
	 * } $options
	 * @return int The client song ID (`Songs.Id`)
	 */
	public function clientCreateSong(
		string $title,
		?string $fileName = 'song.pdf',
		?string $pdfContent = null,
		array $options = [],
		?array $files = null,
	): int {
		$pdo = $this->openSqlite($this->clientDbPath);
		$ts = $this->nextTimestamp();
		$tsMs = $ts * 1000;

		$nextSongId = ((int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM Songs')->fetchColumn()) + 1;
		$sortTitle = $options['sortTitle'] ?? $title;
		$stars = $options['stars'] ?? 0;
		$difficulty = $options['difficulty'] ?? 0;
		$duration = $options['duration'] ?? 0;
		$keywords = $options['keywords'] ?? '';
		$custom = $options['custom'] ?? '';
		$custom2 = $options['custom2'] ?? '';

		$stmt = $pdo->prepare(
			'INSERT INTO Songs (Id, Title, SortTitle, Difficulty, Custom, Custom2, Keywords, Stars, CreationDate, LastModified, Duration)
			 VALUES (:id, :title, :sortTitle, :difficulty, :custom, :custom2, :keywords, :stars, :creationDate, :lastModified, :duration)'
		);
		$stmt->execute([
			':id' => $nextSongId,
			':title' => $title,
			':sortTitle' => $sortTitle,
			':difficulty' => $difficulty,
			':custom' => $custom,
			':custom2' => $custom2,
			':keywords' => $keywords,
			':stars' => $stars,
			':creationDate' => $tsMs,
			':lastModified' => $tsMs,
			':duration' => $duration,
		]);

		if (isset($options['notes']) && $options['notes'] !== '') {
			$nextNoteId = ((int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM SongNotes')->fetchColumn()) + 1;
			$noteStmt = $pdo->prepare(
				'INSERT INTO SongNotes (Id, SongId, Notes, TextSize, Alignment, ShowNotesOnLoad, DisplayTime)
				 VALUES (:id, :songId, :notes, 24, 0, 0, 5)'
			);
			$noteStmt->execute([
				':id' => $nextNoteId,
				':songId' => $nextSongId,
				':notes' => $options['notes'],
			]);
		}

		$filesToCreate = [];
		if ($files !== null) {
			foreach ($files as $fName => $fContent) {
				$filesToCreate[(string)$fName] = (string)$fContent;
			}
		} elseif ($fileName !== null && $fileName !== '') {
			$pages = $options['pages'] ?? 1;
			$filesToCreate[$fileName] = $pdfContent ?? self::generateValidPdf($title, $pages);
		}

		foreach ($filesToCreate as $fName => $content) {
			$relFile = ltrim($fName, '/');
			$localPath = $this->clientFilesDir . '/' . $relFile;
			$localDir = dirname($localPath);
			if (!is_dir($localDir)) {
				mkdir($localDir, 0777, true);
			}
			file_put_contents($localPath, $content);

			$fileSize = strlen($content);
			$fileHash = MobileSheetsPdf::contentHash($content);
			$pageCount = MobileSheetsPdf::pageCount($content);
			$pageOrder = MobileSheetsPdf::pageOrder($pageCount);
			$webdavPath = MobileSheetsPathResolver::WEBDAV_ROOT
				. '/' . $this->userId
				. ($this->libraryFolderName !== '' ? '/' . $this->libraryFolderName : '')
				. '/' . $relFile;

			$nextFileId = ((int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM Files')->fetchColumn()) + 1;
			$fileStmt = $pdo->prepare(
				'INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Type, SourceFilePageCount, FileHash)
				 VALUES (:id, :songId, :path, :pageOrder, :fileSize, :lastModified, 0, :pageCount, :fileHash)'
			);
			$fileStmt->execute([
				':id' => $nextFileId,
				':songId' => $nextSongId,
				':path' => $webdavPath,
				':pageOrder' => $pageOrder,
				':fileSize' => $fileSize,
				':lastModified' => $tsMs,
				':pageCount' => $pageCount,
				':fileHash' => $fileHash,
			]);
		}

		if (!empty($options['entities'])) {
			foreach ($options['entities'] as $tableName => $entityClientIds) {
				foreach ($entityClientIds as $entityClientId) {
					$this->clientAddSongToEntity($tableName, (int)$entityClientId, $nextSongId);
				}
			}
		}

		$this->clientDbDirty = true;
		return $nextSongId;
	}

	/**
	 * Update an existing song's metadata and/or notes on the MobileSheets client.
	 *
	 * @param array<string, mixed> $fields Column values for `Songs` (e.g. Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2) or 'Notes' for `SongNotes`.
	 */
	public function clientUpdateSong(int $clientSongId, array $fields): void {
		$pdo = $this->openSqlite($this->clientDbPath);
		$ts = $this->nextTimestamp();
		$tsMs = $ts * 1000;

		if (array_key_exists('Notes', $fields)) {
			$noteText = (string)$fields['Notes'];
			unset($fields['Notes']);
			$existingNoteId = $pdo->query('SELECT Id FROM SongNotes WHERE SongId = ' . $clientSongId)->fetchColumn();
			if ($noteText === '') {
				$pdo->exec('DELETE FROM SongNotes WHERE SongId = ' . $clientSongId);
			} elseif ($existingNoteId !== false) {
				$stmt = $pdo->prepare('UPDATE SongNotes SET Notes = :notes WHERE Id = :id');
				$stmt->execute([':notes' => $noteText, ':id' => (int)$existingNoteId]);
			} else {
				$nextNoteId = ((int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM SongNotes')->fetchColumn()) + 1;
				$stmt = $pdo->prepare(
					'INSERT INTO SongNotes (Id, SongId, Notes, TextSize, Alignment, ShowNotesOnLoad, DisplayTime)
					 VALUES (:id, :songId, :notes, 24, 0, 0, 5)'
				);
				$stmt->execute([':id' => $nextNoteId, ':songId' => $clientSongId, ':notes' => $noteText]);
			}
		}

		$fields['LastModified'] = $tsMs;
		$sets = [];
		$params = [':id' => $clientSongId];
		foreach ($fields as $col => $val) {
			$paramName = ':c_' . $col;
			$sets[] = '"' . str_replace('"', '""', $col) . '" = ' . $paramName;
			$params[$paramName] = $val;
		}
		$sql = 'UPDATE Songs SET ' . implode(', ', $sets) . ' WHERE Id = :id';
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);

		$this->clientDbDirty = true;
	}

	/**
	 * Delete a song on the MobileSheets client.
	 */
	public function clientDeleteSong(int $clientSongId, bool $deleteLocalFile = true): void {
		$pdo = $this->openSqlite($this->clientDbPath);

		if ($deleteLocalFile && MobileSheetsTableHelper::tableExists($pdo, 'Files')) {
			$stmt = $pdo->prepare('SELECT Path FROM Files WHERE SongId = :songId');
			$stmt->execute([':songId' => $clientSongId]);
			while ($row = $stmt->fetch()) {
				$relPath = $this->extractLibraryRelativePath((string)($row['Path'] ?? ''));
				if ($relPath !== '' && is_file($this->clientFilesDir . '/' . $relPath)) {
					@unlink($this->clientFilesDir . '/' . $relPath);
				}
			}
		}

		$pdo->exec('DELETE FROM Files WHERE SongId = ' . $clientSongId);
		$pdo->exec('DELETE FROM SongNotes WHERE SongId = ' . $clientSongId);
		foreach (MobileSheetsEntityConfig::BY_TABLE as $cfg) {
			$linkTable = $cfg['linkTable'];
			if (MobileSheetsTableHelper::tableExists($pdo, $linkTable)) {
				$pdo->exec('DELETE FROM "' . str_replace('"', '""', $linkTable) . '" WHERE SongId = ' . $clientSongId);
			}
		}
		$pdo->exec('DELETE FROM Songs WHERE Id = ' . $clientSongId);

		$this->clientDbDirty = true;
	}

	/**
	 * Delete a file row from `Files` on the MobileSheets client.
	 */
	public function clientDeleteFile(int $clientFileId, bool $deleteLocalFile = true): void {
		$pdo = $this->openSqlite($this->clientDbPath);
		if ($deleteLocalFile) {
			$stmt = $pdo->prepare('SELECT Path FROM Files WHERE Id = :id');
			$stmt->execute([':id' => $clientFileId]);
			$row = $stmt->fetch();
			if ($row !== false) {
				$relPath = $this->extractLibraryRelativePath((string)($row['Path'] ?? ''));
				if ($relPath !== '' && is_file($this->clientFilesDir . '/' . $relPath)) {
					@unlink($this->clientFilesDir . '/' . $relPath);
				}
			}
		}
		$pdo->exec('DELETE FROM Files WHERE Id = ' . $clientFileId);
		$this->clientDbDirty = true;
	}

	/**
	 * Add a physical PDF file and `Files` row to an existing song on the MobileSheets client.
	 *
	 * @return int The client `Files.Id` assigned to the newly added file
	 */
	public function clientAddFileToSong(
		int $clientSongId,
		string $fileName,
		?string $pdfContent = null,
		int $pages = 1,
		?string $pageOrder = null,
		bool $reuseDeletedId = false,
	): int {
		$pdo = $this->openSqlite($this->clientDbPath);
		$ts = $this->nextTimestamp();
		$tsMs = $ts * 1000;

		$relFile = ltrim($fileName, '/');
		$content = $pdfContent ?? self::generateValidPdf('Song:' . $clientSongId . ':' . $relFile, $pages);

		$localPath = $this->clientFilesDir . '/' . $relFile;
		$localDir = dirname($localPath);
		if (!is_dir($localDir)) {
			mkdir($localDir, 0777, true);
		}
		file_put_contents($localPath, $content);

		$fileSize = strlen($content);
		$fileHash = MobileSheetsPdf::contentHash($content);
		$pageCount = MobileSheetsPdf::pageCount($content);
		$effectivePageOrder = $pageOrder ?? MobileSheetsPdf::pageOrder($pageCount);
		$webdavPath = MobileSheetsPathResolver::WEBDAV_ROOT
			. '/' . $this->userId
			. ($this->libraryFolderName !== '' ? '/' . $this->libraryFolderName : '')
			. '/' . $relFile;

		$maxInFiles = (int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM Files')->fetchColumn();
		$maxInSyncMeta = 0;
		if (!$reuseDeletedId && MobileSheetsTableHelper::tableExists($pdo, '_nc_sync_metadata')) {
			$maxInSyncMeta = (int)$pdo->query("SELECT COALESCE(MAX(client_id), 0) FROM _nc_sync_metadata WHERE entity_type = 'file'")->fetchColumn();
		}
		$nextFileId = max($maxInFiles, $maxInSyncMeta) + 1;

		$fileStmt = $pdo->prepare(
			'INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Type, SourceFilePageCount, FileHash)
			 VALUES (:id, :songId, :path, :pageOrder, :fileSize, :lastModified, 0, :pageCount, :fileHash)'
		);
		$fileStmt->execute([
			':id' => $nextFileId,
			':songId' => $clientSongId,
			':path' => $webdavPath,
			':pageOrder' => $effectivePageOrder,
			':fileSize' => $fileSize,
			':lastModified' => $tsMs,
			':pageCount' => $pageCount,
			':fileHash' => $fileHash,
		]);

		$songStmt = $pdo->prepare('UPDATE Songs SET LastModified = :lastModified WHERE Id = :id');
		$songStmt->execute([
			':lastModified' => $tsMs,
			':id' => $clientSongId,
		]);

		$this->clientDbDirty = true;
		return $nextFileId;
	}

	/**
	 * Create an entity (e.g. in `Collections`, `Composer`, `Books`, `Artists`, `Genres`, `Keys`, `CustomGroup`, `Years`, `Signature`, `SourceType`) on the MobileSheets client.
	 */
	public function clientCreateEntity(string $tableName, string $name, int $sortBy = 1, int $ascending = 1): int {
		$cfg = MobileSheetsEntityConfig::BY_TABLE[$tableName]
			?? throw new \InvalidArgumentException('Unsupported MobileSheets entity table: ' . $tableName);

		$pdo = $this->openSqlite($this->clientDbPath);
		$ts = $this->nextTimestamp();
		$tsMs = $ts * 1000;

		$nextId = ((int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM "' . str_replace('"', '""', $tableName) . '"')->fetchColumn()) + 1;
		$cols = MobileSheetsTableHelper::getTableColumns($pdo, $tableName);
		$nameCol = MobileSheetsTableHelper::resolveEntityNameColumn($cols, $cfg['nameCol']) ?? $cfg['nameCol'];

		$insertCols = ['Id', $nameCol];
		$insertVals = [':id' => $nextId, ':name' => $name];

		if (in_array('SortBy', $cols, true)) {
			$insertCols[] = 'SortBy';
			$insertVals[':sortBy'] = $sortBy;
		}
		if (in_array('Ascending', $cols, true)) {
			$insertCols[] = 'Ascending';
			$insertVals[':ascending'] = $ascending;
		}
		if (in_array('DateCreated', $cols, true)) {
			$insertCols[] = 'DateCreated';
			$insertVals[':dateCreated'] = $tsMs;
		}
		if (in_array('LastModified', $cols, true)) {
			$insertCols[] = 'LastModified';
			$insertVals[':lastModified'] = $tsMs;
		}

		$quotedCols = array_map(static fn (string $c): string => '"' . str_replace('"', '""', $c) . '"', $insertCols);
		$sql = 'INSERT INTO "' . str_replace('"', '""', $tableName) . '" (' . implode(', ', $quotedCols) . ') VALUES (' . implode(', ', array_keys($insertVals)) . ')';
		$stmt = $pdo->prepare($sql);
		$stmt->execute($insertVals);

		$this->clientDbDirty = true;
		return $nextId;
	}

	/**
	 * Rename an entity on the MobileSheets client.
	 */
	public function clientRenameEntity(string $tableName, int $entityClientId, string $newName): void {
		$cfg = MobileSheetsEntityConfig::BY_TABLE[$tableName]
			?? throw new \InvalidArgumentException('Unsupported MobileSheets entity table: ' . $tableName);

		$pdo = $this->openSqlite($this->clientDbPath);
		$ts = $this->nextTimestamp();
		$tsMs = $ts * 1000;

		$cols = MobileSheetsTableHelper::getTableColumns($pdo, $tableName);
		$nameCol = MobileSheetsTableHelper::resolveEntityNameColumn($cols, $cfg['nameCol']) ?? $cfg['nameCol'];

		$sets = ['"' . str_replace('"', '""', $nameCol) . '" = :name'];
		$params = [':name' => $newName, ':id' => $entityClientId];
		if (in_array('LastModified', $cols, true)) {
			$sets[] = '"LastModified" = :lastModified';
			$params[':lastModified'] = $tsMs;
		}

		$sql = 'UPDATE "' . str_replace('"', '""', $tableName) . '" SET ' . implode(', ', $sets) . ' WHERE Id = :id';
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);

		$this->clientDbDirty = true;
	}

	/**
	 * Delete an entity on the MobileSheets client.
	 */
	public function clientDeleteEntity(string $tableName, int $entityClientId): void {
		$cfg = MobileSheetsEntityConfig::BY_TABLE[$tableName]
			?? throw new \InvalidArgumentException('Unsupported MobileSheets entity table: ' . $tableName);

		$pdo = $this->openSqlite($this->clientDbPath);
		$linkTable = $cfg['linkTable'];
		$linkCol = $cfg['linkCol'];

		if (MobileSheetsTableHelper::tableExists($pdo, $linkTable)) {
			$pdo->exec(
				'DELETE FROM "' . str_replace('"', '""', $linkTable) . '" WHERE "' . str_replace('"', '""', $linkCol) . '" = ' . $entityClientId
			);
		}
		$pdo->exec('DELETE FROM "' . str_replace('"', '""', $tableName) . '" WHERE Id = ' . $entityClientId);

		$this->clientDbDirty = true;
	}

	/**
	 * Link a song to an entity on the MobileSheets client (e.g., add song to Collection, Composer, Book, etc.).
	 */
	public function clientAddSongToEntity(string $tableName, int $entityClientId, int $songClientId): void {
		$cfg = MobileSheetsEntityConfig::BY_TABLE[$tableName]
			?? throw new \InvalidArgumentException('Unsupported MobileSheets entity table: ' . $tableName);

		$pdo = $this->openSqlite($this->clientDbPath);
		$linkTable = $cfg['linkTable'];
		$linkCol = $cfg['linkCol'];

		$checkStmt = $pdo->prepare(
			'SELECT Id FROM "' . str_replace('"', '""', $linkTable) . '" WHERE "' . str_replace('"', '""', $linkCol) . '" = :eid AND SongId = :sid'
		);
		$checkStmt->execute([':eid' => $entityClientId, ':sid' => $songClientId]);
		if ($checkStmt->fetchColumn() === false) {
			$nextId = ((int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM "' . str_replace('"', '""', $linkTable) . '"')->fetchColumn()) + 1;
			$insStmt = $pdo->prepare(
				'INSERT INTO "' . str_replace('"', '""', $linkTable) . '" (Id, "' . str_replace('"', '""', $linkCol) . '", SongId) VALUES (:id, :eid, :sid)'
			);
			$insStmt->execute([':id' => $nextId, ':eid' => $entityClientId, ':sid' => $songClientId]);
		}

		$tsMs = $this->nextTimestamp() * 1000;
		$pdo->exec('UPDATE Songs SET LastModified = ' . $tsMs . ' WHERE Id = ' . $songClientId);

		$this->clientDbDirty = true;
	}

	/**
	 * Remove a song from an entity on the MobileSheets client.
	 */
	public function clientRemoveSongFromEntity(string $tableName, int $entityClientId, int $songClientId): void {
		$cfg = MobileSheetsEntityConfig::BY_TABLE[$tableName]
			?? throw new \InvalidArgumentException('Unsupported MobileSheets entity table: ' . $tableName);

		$pdo = $this->openSqlite($this->clientDbPath);
		$linkTable = $cfg['linkTable'];
		$linkCol = $cfg['linkCol'];

		$delStmt = $pdo->prepare(
			'DELETE FROM "' . str_replace('"', '""', $linkTable) . '" WHERE "' . str_replace('"', '""', $linkCol) . '" = :eid AND SongId = :sid'
		);
		$delStmt->execute([':eid' => $entityClientId, ':sid' => $songClientId]);

		$tsMs = $this->nextTimestamp() * 1000;
		$pdo->exec('UPDATE Songs SET LastModified = ' . $tsMs . ' WHERE Id = ' . $songClientId);

		$this->clientDbDirty = true;
	}

	// =========================================================================
	// Nextcloud Operations
	// =========================================================================

	/**
	 * Create a song in Nextcloud, optionally uploading a PDF file.
	 *
	 * @param array{
	 *     composerIds?: list<int>,
	 *     bookIds?: list<int>,
	 *     collectionIds?: list<int>,
	 *     artistIds?: list<int>,
	 *     genreIds?: list<int>,
	 *     keyIds?: list<int>,
	 *     customGroupIds?: list<int>,
	 *     yearIds?: list<int>,
	 *     signatureIds?: list<int>,
	 *     sourceTypeIds?: list<int>,
	 *     instrumentIds?: list<int>,
	 *     stars?: int,
	 *     difficulty?: int,
	 *     duration?: int,
	 *     keywords?: string,
	 *     custom?: string,
	 *     sortTitle?: string,
	 *     opus?: string,
	 *     catalogNumber?: string,
	 *     songNumber?: string,
	 *     pages?: int
	 * } $options
	 * @return int The internal Nextcloud song ID (`sheetmusic_songs.id`)
	 */
	public function ncCreateSong(
		string $title,
		?string $fileName = null,
		?string $pdfContent = null,
		array $options = [],
	): int {
		$data = $this->songService->createSong(
			user: $this->user,
			libraryId: $this->libraryId,
			title: $title,
			composerIds: $options['composerIds'] ?? [],
			bookIds: $options['bookIds'] ?? [],
			collectionIds: $options['collectionIds'] ?? [],
			artistIds: $options['artistIds'] ?? [],
			genreIds: $options['genreIds'] ?? [],
			keyIds: $options['keyIds'] ?? [],
			customGroupIds: $options['customGroupIds'] ?? [],
			yearIds: $options['yearIds'] ?? [],
			signatureIds: $options['signatureIds'] ?? [],
			sourceTypeIds: $options['sourceTypeIds'] ?? [],
			stars: $options['stars'] ?? 0,
			difficulty: $options['difficulty'] ?? 0,
			duration: $options['duration'] ?? 0,
			keywords: $options['keywords'] ?? '',
			custom: $options['custom'] ?? '',
			sortTitle: $options['sortTitle'] ?? '',
			bookmarks: $options['bookmarks'] ?? [],
			audioFiles: [],
			opus: $options['opus'] ?? '',
			catalogNumber: $options['catalogNumber'] ?? '',
			songNumber: $options['songNumber'] ?? '',
			instrumentIds: $options['instrumentIds'] ?? [],
		);
		Assert::assertNotNull($data, 'SongService::createSong should return LibraryData');

		$songModel = $this->songMapper->findByTitle($this->libraryId, $title);
		Assert::assertNotNull($songModel, 'Newly created song must exist in InternalSongMapper');
		Assert::assertNotNull($songModel->id);

		$ts = $this->nextTimestamp();
		$songModel->updatedAt = $ts;
		$this->songMapper->update($songModel);

		if ($fileName !== null && $fileName !== '') {
			$pages = $options['pages'] ?? 1;
			$content = $pdfContent ?? self::generateValidPdf($title, $pages);
			$this->ncUploadFileToSong($songModel->id, $fileName, $content);
		}

		return $songModel->id;
	}

	/**
	 * Upload a file and attach it to an existing song in Nextcloud via SongFileService.
	 */
	public function ncUploadFileToSong(int $songId, string $fileName, ?string $pdfContent = null): LibraryData {
		$content = $pdfContent ?? self::generateValidPdf($fileName, 1);
		$tmpFile = $this->tempDir . '/upload_' . bin2hex(random_bytes(4)) . '_' . basename($fileName);
		file_put_contents($tmpFile, $content);

		try {
			$data = $this->songFileService->uploadSongFile(
				$this->user,
				$this->libraryId,
				$songId,
				[
					'tmp_name' => $tmpFile,
					'name' => basename($fileName),
					'size' => strlen($content),
					'error' => UPLOAD_ERR_OK,
				],
			);
		} finally {
			@unlink($tmpFile);
		}

		Assert::assertNotNull($data, 'SongFileService::uploadSongFile should return LibraryData');

		$ts = $this->nextTimestamp();
		foreach ($this->songFileMapper->findAllForSong($songId) as $sf) {
			if ($sf->clientId === null) {
				$sf->updatedAt = $ts;
				$this->songFileMapper->update($sf);
			}
		}

		$song = $this->songMapper->findById($songId);
		if ($song !== null) {
			$song->updatedAt = $ts;
			$this->songMapper->update($song);
		}

		return $data;
	}

	/**
	 * Attach a 1-page blank PDF to a fileless song in Nextcloud so it can be synced with MobileSheets.
	 */
	public function ncAttachBlankPageToSong(int $songId, string $fileName = 'blank_page.pdf'): LibraryData {
		return $this->ncUploadFileToSong($songId, $fileName, self::generateValidPdf('Blank Page', 1));
	}

	/**
	 * Update a song's metadata and entity relationships in Nextcloud via SongService::updateSong.
	 *
	 * @param array{
	 *     composerIds?: list<int>,
	 *     bookIds?: list<int>,
	 *     collectionIds?: list<int>,
	 *     artistIds?: list<int>,
	 *     genreIds?: list<int>,
	 *     keyIds?: list<int>,
	 *     customGroupIds?: list<int>,
	 *     yearIds?: list<int>,
	 *     signatureIds?: list<int>,
	 *     sourceTypeIds?: list<int>,
	 *     instrumentIds?: list<int>,
	 *     stars?: int,
	 *     difficulty?: int,
	 *     duration?: int,
	 *     keywords?: string,
	 *     custom?: string,
	 *     sortTitle?: string,
	 *     opus?: string,
	 *     catalogNumber?: string,
	 *     songNumber?: string
	 * } $options
	 */
	public function ncUpdateSong(int $songId, string $title, array $options = []): LibraryData {
		$existing = $this->songMapper->findById($songId);
		Assert::assertNotNull($existing, 'Song to update in Nextcloud must exist');

		$data = $this->songService->updateSong(
			user: $this->user,
			libraryId: $this->libraryId,
			songId: $songId,
			title: $title,
			composerIds: $options['composerIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'composer'),
			bookIds: $options['bookIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'book'),
			collectionIds: $options['collectionIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'collection'),
			artistIds: $options['artistIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'artist'),
			genreIds: $options['genreIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'genre'),
			keyIds: $options['keyIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'key'),
			customGroupIds: $options['customGroupIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'customgroup'),
			yearIds: $options['yearIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'year'),
			signatureIds: $options['signatureIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'signature'),
			sourceTypeIds: $options['sourceTypeIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'sourcetype'),
			stars: $options['stars'] ?? $existing->rating,
			difficulty: $options['difficulty'] ?? $existing->difficulty,
			duration: $options['duration'] ?? $existing->duration,
			keywords: $options['keywords'] ?? ($existing->keywords ?? ''),
			custom: $options['custom'] ?? ($existing->notes ?? ''),
			sortTitle: $options['sortTitle'] ?? ($existing->sortTitle ?? ''),
			bookmarks: $options['bookmarks'] ?? [],
			audioFiles: [],
			opus: $options['opus'] ?? ($existing->opus ?? ''),
			catalogNumber: $options['catalogNumber'] ?? ($existing->catalogNumber ?? ''),
			songNumber: $options['songNumber'] ?? ($existing->songNumber ?? ''),
			instrumentIds: $options['instrumentIds'] ?? $this->entityMapper->findEntityIdsForSong($songId, 'instrument'),
		);
		Assert::assertNotNull($data);

		$updated = $this->songMapper->findById($songId);
		if ($updated !== null) {
			$updated->updatedAt = $this->nextTimestamp();
			$this->songMapper->update($updated);
		}

		return $data;
	}

	/**
	 * Batch update song entities in Nextcloud via SongService::batchUpdateSongs.
	 *
	 * @param list<int> $songIds
	 * @param array{
	 *     composers?: ?list<int>,
	 *     books?: ?list<int>,
	 *     collections?: ?list<int>,
	 *     artists?: ?list<int>,
	 *     genres?: ?list<int>,
	 *     keys?: ?list<int>,
	 *     customGroups?: ?list<int>,
	 *     years?: ?list<int>,
	 *     signatures?: ?list<int>,
	 *     sourceTypes?: ?list<int>,
	 *     instruments?: ?list<int>
	 * } $entities
	 */
	public function ncBatchUpdateSongs(array $songIds, array $entities): LibraryData {
		$data = $this->songService->batchUpdateSongs(
			user: $this->user,
			libraryId: $this->libraryId,
			songIds: $songIds,
			composers: $entities['composers'] ?? null,
			books: $entities['books'] ?? null,
			collections: $entities['collections'] ?? null,
			artists: $entities['artists'] ?? null,
			genres: $entities['genres'] ?? null,
			keys: $entities['keys'] ?? null,
			customGroups: $entities['customGroups'] ?? null,
			years: $entities['years'] ?? null,
			signatures: $entities['signatures'] ?? null,
			sourceTypes: $entities['sourceTypes'] ?? null,
			instruments: $entities['instruments'] ?? null,
		);
		Assert::assertNotNull($data);

		$ts = $this->nextTimestamp();
		foreach ($songIds as $sid) {
			$song = $this->songMapper->findById((int)$sid);
			if ($song !== null) {
				$song->updatedAt = $ts;
				$this->songMapper->update($song);
			}
		}

		return $data;
	}

	/**
	 * Delete a song in Nextcloud via SongService::deleteSong.
	 */
	public function ncDeleteSong(int $songId, bool $deleteFiles = true): LibraryData {
		$data = $this->songService->deleteSong($this->user, $songId, $this->libraryId, $deleteFiles);
		Assert::assertNotNull($data);
		return $data;
	}

	/**
	 * Batch-delete songs in Nextcloud via SongService::batchDeleteSongs.
	 *
	 * @param list<int> $songIds
	 */
	public function ncBatchDeleteSongs(array $songIds, bool $deleteFiles = true): LibraryData {
		$data = $this->songService->batchDeleteSongs($this->user, $this->libraryId, $songIds, $deleteFiles);
		Assert::assertNotNull($data);
		return $data;
	}

	/**
	 * Delete a song file in Nextcloud via SongFileService::deleteSongFile.
	 */
	public function ncDeleteSongFile(int $songId, int $songFileId): LibraryData {
		$data = $this->songFileService->deleteSongFile($this->user, $this->libraryId, $songId, $songFileId);
		Assert::assertNotNull($data);
		$ts = $this->nextTimestamp();
		$fileRec = $this->songFileMapper->findById($songFileId);
		if ($fileRec !== null && $fileRec->deletedAt !== null) {
			$fileRec->deletedAt = $ts;
			$fileRec->updatedAt = $ts;
			$this->songFileMapper->update($fileRec);
		}
		$song = $this->songMapper->findById($songId);
		if ($song !== null) {
			$song->updatedAt = $ts;
			$this->songMapper->update($song);
		}
		return $data;
	}

	/**
	 * Create an entity in Nextcloud via EntityService::createEntity.
	 *
	 * @return int Internal entity ID (`sheetmusic_entities.id`)
	 */
	public function ncCreateEntity(string $entityType, string $nameOrTitle): int {
		$res = $this->entityService->createEntity($this->user, $this->libraryId, $entityType, $nameOrTitle);
		Assert::assertNotNull($res);
		$id = (int)$res['id'];
		$entity = $this->entityMapper->findById($id);
		if ($entity !== null) {
			$entity->updatedAt = $this->nextTimestamp();
			$this->entityMapper->update($entity);
		}
		return $id;
	}

	/**
	 * Delete an entity in Nextcloud via EntityService::deleteEntity.
	 */
	public function ncDeleteEntity(string $entityType, int $entityId): LibraryData {
		$data = $this->entityService->deleteEntity($this->user, $this->libraryId, $entityType, $entityId);
		Assert::assertNotNull($data);
		return $data;
	}

	/**
	 * Merge duplicate entities in Nextcloud via EntityService::mergeEntities.
	 *
	 * @param list<int> $duplicateIds
	 */
	public function ncMergeEntities(string $entityType, int $keepId, array $duplicateIds): LibraryData {
		$res = $this->entityService->mergeEntities($this->user, $this->libraryId, $entityType, $keepId, $duplicateIds);
		Assert::assertNotNull($res);
		$ts = $this->nextTimestamp();
		$keep = $this->entityMapper->findById($keepId);
		if ($keep !== null) {
			$keep->updatedAt = $ts;
			$this->entityMapper->update($keep);
		}
		foreach ($this->entityMapper->findSongIdsForEntity($keepId) as $sid) {
			$song = $this->songMapper->findById($sid);
			if ($song !== null) {
				$song->updatedAt = $ts;
				$this->songMapper->update($song);
			}
		}
		return $res['libraryData'];
	}

	/**
	 * Merge song $droppedId into song $keepId in Nextcloud via SongService::mergeSong.
	 *
	 * @param array<string, mixed> $merged
	 */
	public function ncMergeSong(int $keepId, int $droppedId, array $merged): LibraryData {
		$data = $this->songService->mergeSong($this->user, $this->libraryId, $keepId, $droppedId, $merged);
		Assert::assertNotNull($data);
		$song = $this->songMapper->findById($keepId);
		if ($song !== null) {
			$song->updatedAt = $this->nextTimestamp();
			$this->songMapper->update($song);
		}
		return $data;
	}

	/**
	 * Update an existing song file's PDF content in Nextcloud via SongFileService::updateSongFile.
	 */
	public function ncUpdateSongFile(int $songId, int $songFileId, string $newPdfContent): LibraryData {
		$tmpFile = $this->tempDir . '/update_file_' . bin2hex(random_bytes(4)) . '.pdf';
		file_put_contents($tmpFile, $newPdfContent);

		try {
			$data = $this->songFileService->updateSongFile(
				$this->user,
				$this->libraryId,
				$songId,
				$songFileId,
				[
					'tmp_name' => $tmpFile,
					'name' => 'updated.pdf',
					'size' => strlen($newPdfContent),
					'error' => UPLOAD_ERR_OK,
				],
			);
		} finally {
			@unlink($tmpFile);
		}

		Assert::assertNotNull($data);
		$fileRec = $this->songFileMapper->findById($songFileId);
		if ($fileRec !== null) {
			$fileRec->updatedAt = $this->nextTimestamp();
			$this->songFileMapper->update($fileRec);
		}
		$song = $this->songMapper->findById($songId);
		if ($song !== null) {
			$song->updatedAt = $this->nextTimestamp();
			$this->songMapper->update($song);
		}

		return $data;
	}

	/**
	 * Detach a song file in Nextcloud without deleting the physical file via SongFileService::detachSongFile.
	 */
	public function ncDetachSongFile(int $songId, int $songFileId): LibraryData {
		$data = $this->songFileService->detachSongFile($this->user, $this->libraryId, $songId, $songFileId);
		Assert::assertNotNull($data);
		return $data;
	}

	/**
	 * Attach an existing file in Nextcloud user storage to a song via SongFileService::attachNextcloudFile.
	 */
	public function ncAttachNextcloudFile(int $songId, string $sourcePath, string $action = 'copy'): LibraryData {
		$data = $this->songFileService->attachNextcloudFile($this->user, $this->libraryId, $songId, $sourcePath, $action);
		Assert::assertNotNull($data);
		$song = $this->songMapper->findById($songId);
		if ($song !== null) {
			$song->updatedAt = $this->nextTimestamp();
			$this->songMapper->update($song);
		}
		return $data;
	}

	/**
	 * Drop a new physical file directly into the Nextcloud library folder, triggering
	 * SheetMusicFileEventListener::onNodeCreated auto-indexing.
	 */
	public function ncDropFileIntoLibraryFolder(string $relativePath, ?string $pdfContent = null, int $pages = 1): File {
		$libraryFolder = $this->getLibraryFolder();
		$relPath = ltrim($relativePath, '/');
		$this->ensureNextcloudSubfolders($libraryFolder, $relPath);
		$content = $pdfContent ?? self::generateValidPdf(basename($relPath), $pages);
		$ncFile = $libraryFolder->newFile($relPath, $content);
		$this->fileEventListener->onNodeCreated($ncFile);
		return $ncFile;
	}

	/**
	 * Replace a file's PDF content on the MobileSheets client and update its `Files` table facts.
	 */
	public function clientUpdateFileContent(int $clientFileId, string $newPdfContent, ?string $newPageOrder = null): void {
		$pdo = $this->openSqlite($this->clientDbPath);
		$stmt = $pdo->prepare('SELECT * FROM Files WHERE Id = :id');
		$stmt->execute([':id' => $clientFileId]);
		$row = $stmt->fetch();
		Assert::assertIsArray($row, "Client file row {$clientFileId} must exist");

		$relPath = $this->extractLibraryRelativePath((string)$row['Path']);
		$localPath = $this->clientFilesDir . '/' . $relPath;
		$localDir = dirname($localPath);
		if (!is_dir($localDir)) {
			mkdir($localDir, 0777, true);
		}
		file_put_contents($localPath, $newPdfContent);

		$ts = $this->nextTimestamp();
		$tsMs = $ts * 1000;
		$fileSize = strlen($newPdfContent);
		$fileHash = MobileSheetsPdf::contentHash($newPdfContent);
		$pageCount = MobileSheetsPdf::pageCount($newPdfContent);
		$pageOrder = $newPageOrder ?? MobileSheetsPdf::pageOrder($pageCount);

		$upd = $pdo->prepare(
			'UPDATE Files SET FileSize = :size, FileHash = :hash, SourceFilePageCount = :pages, PageOrder = :order, LastModified = :lastMod WHERE Id = :id'
		);
		$upd->execute([
			':size' => $fileSize,
			':hash' => $fileHash,
			':pages' => $pageCount,
			':order' => $pageOrder,
			':lastMod' => $tsMs,
			':id' => $clientFileId,
		]);
		$this->clientDbDirty = true;
	}

	/**
	 * Create a rich, pre-populated MobileSheets client library (with songs, multi-page PDFs,
	 * collections, composers, books, artists, genres, keys, years, signatures, custom groups,
	 * song notes, custom attributes, setlists, bookmarks, annotations, audio files, tempos,
	 * and display settings) before syncing with Nextcloud.
	 */
	public function populateRichMobileSheetsClientLibrary(): void {
		// 1. Create entities across all MobileSheets entity tables
		$compBach = $this->clientCreateEntity('Composer', 'Johann Sebastian Bach');
		$compChopin = $this->clientCreateEntity('Composer', 'Frédéric Chopin');
		$compMiles = $this->clientCreateEntity('Composer', 'Miles Davis');

		$colBaroque = $this->clientCreateEntity('Collections', 'Baroque Masterworks', sortBy: 1, ascending: 1);
		$colRomantic = $this->clientCreateEntity('Collections', 'Romantic Nocturnes', sortBy: 1, ascending: 1);
		$colConcertOrder = $this->clientCreateEntity('Collections', 'Recital Program (Manual Order)', sortBy: 0, ascending: 1);

		$bookWtc = $this->clientCreateEntity('Books', 'Well-Tempered Clavier Book I');
		$bookReal = $this->clientCreateEntity('Books', 'The Real Book Vol. 1');

		$artistGould = $this->clientCreateEntity('Artists', 'Glenn Gould');
		$artistRubinstein = $this->clientCreateEntity('Artists', 'Arthur Rubinstein');

		$genreClassical = $this->clientCreateEntity('Genres', 'Classical');
		$genreJazz = $this->clientCreateEntity('Genres', 'Modal Jazz');

		$keyCMaj = $this->clientCreateEntity('Key', 'C Major');
		$keyEbMaj = $this->clientCreateEntity('Key', 'E-Flat Major');
		$keyDMin = $this->clientCreateEntity('Key', 'D Dorian');

		$year1722 = $this->clientCreateEntity('Years', '1722');
		$year1832 = $this->clientCreateEntity('Years', '1832');
		$year1959 = $this->clientCreateEntity('Years', '1959');

		$sig44 = $this->clientCreateEntity('Signature', '4/4');
		$sig128 = $this->clientCreateEntity('Signature', '12/8');

		$srcUrtext = $this->clientCreateEntity('SourceType', 'Henle Urtext');
		$srcLeadSheet = $this->clientCreateEntity('SourceType', 'Lead Sheet');

		$grpPiano = $this->clientCreateEntity('CustomGroup', 'Solo Piano');
		$grpQuintet = $this->clientCreateEntity('CustomGroup', 'Jazz Quintet');

		// 2. Create 3 rich songs with PDFs and multi-entity links
		$s1 = $this->clientCreateSong(
			title: 'Prelude & Fugue No. 1 in C Major',
			fileName: 'wtc1_prelude_fugue_1.pdf',
			options: [
				'sortTitle' => 'Prelude & Fugue 01 in C Major, BWV 846',
				'stars' => 5,
				'difficulty' => 3,
				'duration' => 255,
				'keywords' => 'baroque, counterpoint, fugue',
				'custom' => 'BWV 846',
				'custom2' => 'Henle Ed.',
				'notes' => 'Keep fugue subject crisp and detached',
				'pages' => 4,
				'entities' => [
					'Composer' => [$compBach],
					'Collections' => [$colBaroque, $colConcertOrder],
					'Books' => [$bookWtc],
					'Artists' => [$artistGould],
					'Genres' => [$genreClassical],
					'Key' => [$keyCMaj],
					'Years' => [$year1722],
					'Signature' => [$sig44],
					'SourceType' => [$srcUrtext],
					'CustomGroup' => [$grpPiano],
				],
			],
		);

		$s2 = $this->clientCreateSong(
			title: 'Nocturne in E-Flat Major, Op. 9 No. 2',
			fileName: 'chopin_nocturne_op9_2.pdf',
			options: [
				'sortTitle' => 'Nocturne Op. 09 No. 2',
				'stars' => 5,
				'difficulty' => 4,
				'duration' => 270,
				'keywords' => 'romantic, rubato, cantabile',
				'custom' => 'Op. 9 No. 2',
				'custom2' => 'Paderewski Ed.',
				'notes' => 'Espressivo dolce with flexible left hand',
				'pages' => 3,
				'entities' => [
					'Composer' => [$compChopin],
					'Collections' => [$colRomantic, $colConcertOrder],
					'Artists' => [$artistRubinstein],
					'Genres' => [$genreClassical],
					'Key' => [$keyEbMaj],
					'Years' => [$year1832],
					'Signature' => [$sig128],
					'SourceType' => [$srcUrtext],
					'CustomGroup' => [$grpPiano],
				],
			],
		);

		$s3 = $this->clientCreateSong(
			title: 'So What',
			fileName: 'so_what_lead.pdf',
			options: [
				'sortTitle' => 'So What',
				'stars' => 4,
				'difficulty' => 2,
				'duration' => 560,
				'keywords' => 'modal, jazz, kind of blue',
				'custom' => 'D Dorian / Eb Dorian',
				'custom2' => 'Concert Pitch',
				'notes' => 'Bass intro, horn answers on 2-note voicings',
				'pages' => 2,
				'entities' => [
					'Composer' => [$compMiles],
					'Collections' => [$colConcertOrder],
					'Books' => [$bookReal],
					'Genres' => [$genreJazz],
					'Key' => [$keyDMin],
					'Years' => [$year1959],
					'Signature' => [$sig44],
					'SourceType' => [$srcLeadSheet],
					'CustomGroup' => [$grpQuintet],
				],
			],
		);

		// 3. Populate auxiliary MobileSheets tables (Setlists, SetlistSong, Bookmarks, AnnotationsBase, DrawAnnotations, AudioFiles, Tempos, SongDisplaySettings)
		$pdo = $this->openSqlite($this->clientDbPath);
		$tsMs = $this->nextTimestamp() * 1000;

		$pdo->exec("INSERT INTO Setlists (Id, Name, LastPage, LastIndex, SortBy, Ascending, DateCreated, LastModified) VALUES
			(1, 'Autumn Recital 2026', 0, 0, 0, 1, {$tsMs}, {$tsMs})");
		$pdo->exec("INSERT INTO SetlistSong (Id, SetlistId, SongId) VALUES
			(1, 1, {$s2}),
			(2, 1, {$s1}),
			(3, 1, {$s3})");
		$pdo->exec("INSERT INTO Bookmarks (Id, SongId, Name, PageNum, ShowInLibrary, DateCreated, LastModified) VALUES
			(1, {$s1}, 'Fugue Subject Entry', 3, 1, {$tsMs}, {$tsMs}),
			(2, {$s2}, 'Coda Cadence', 3, 1, {$tsMs}, {$tsMs})");
		$pdo->exec("INSERT INTO AnnotationsBase (Id, SongId, Page, Type, Opacity, Zoom, ZoomY, Version, SourcePageWidth, SourcePageHeight, Layer) VALUES
			(1, {$s1}, 1, 1, 255, 1.0, 1.0, 1, 612.0, 792.0, 0)");
		$pdo->exec('INSERT INTO DrawAnnotations (Id, BaseId, LineColor, FillColor, LineWidth, DrawMode, PenMode, SmoothMode) VALUES
			(1, 1, -65536, 0, 2.5, 0, 1, 1)');
		$pdo->exec("INSERT INTO Tempos (Id, SongId, Tempo, TempoIndex) VALUES
			(1, {$s1}, 84, 0),
			(2, {$s2}, 66, 0),
			(3, {$s3}, 136, 0)");
		$pdo->exec("INSERT INTO SongDisplaySettings (Id, SongId, UseDefaultAdapter, PortraitAdapterType, LandscapeAdapterType, UseDefaultScaleMode, PortraitScaleMode, LandscapeScaleMode) VALUES
			(1, {$s1}, 0, 1, 2, 0, 1, 1)");

		$this->clientDbDirty = true;
	}

	/**
	 * Set up a pre-existing MobileSheets library directly inside a Nextcloud folder
	 * (with `mobilesheets.db`, `mobilesheets_hashcodes.txt`, and PDF files already sitting in Nextcloud)
	 * and import it via `LibraryService::addLibrary(..., importExistingDb: true)`.
	 */
	public function setupAndImportPreExistingMobileSheetsLibraryInNextcloud(
		string $folderName = 'ImportedMobileSheetsLib',
	): int {
		$this->libraryFolderName = trim($folderName, '/');
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		if ($userFolder->nodeExists($this->libraryFolderName)) {
			$userFolder->get($this->libraryFolderName)->delete();
		}
		$libFolder = $userFolder->newFolder($this->libraryFolderName);

		$this->initEmptyMobileSheetsClient();
		$this->populateRichMobileSheetsClientLibrary();

		// Copy client physical PDF files and mobilesheets.db into the Nextcloud folder BEFORE calling addLibrary
		$clientPdo = $this->openSqlite($this->clientDbPath);
		$stmt = $clientPdo->query('SELECT Path FROM Files');
		if ($stmt !== false) {
			while ($row = $stmt->fetch()) {
				$relPath = $this->extractLibraryRelativePath((string)$row['Path']);
				$localFile = $this->clientFilesDir . '/' . $relPath;
				if ($relPath !== '' && is_file($localFile)) {
					$this->ensureNextcloudSubfolders($libFolder, $relPath);
					$libFolder->newFile($relPath, (string)file_get_contents($localFile));
				}
			}
		}

		$libFolder->newFile('mobilesheets.db', (string)file_get_contents($this->clientDbPath));

		// Register library via LibraryService::addLibrary pointing to /Folder/mobilesheets.db with importExistingDb = true
		$added = $this->libraryService->addLibrary(
			$this->userId,
			'/' . $this->libraryFolderName . '/mobilesheets.db',
			importExistingDb: true,
		);
		Assert::assertNotNull($added, 'LibraryService::addLibrary with importExistingDb must succeed');

		$libraries = $this->libraryService->getLibraries($this->userId);
		Assert::assertNotEmpty($libraries);
		$this->libraryId = (int)$libraries[0]->id;
		$this->libraryService->updateSettings($this->userId, $this->libraryId, autoPaths: false, autoPdfMetadata: false);

		return $this->libraryId;
	}

	// =========================================================================
	// Bidirectional Sync & Assertions
	// =========================================================================

	/**
	 * Perform a full bidirectional MobileSheets <-> Nextcloud sync cycle and verify
	 * that the resulting state is conflict-free and has zero diff.
	 *
	 * @param bool $allowSongsWithoutFilesInNc Set true when testing fileless songs in Nextcloud
	 *                                         that should remain in Nextcloud without syncing to client.
	 * @return array<string, int>|null Import stats if client DB was imported
	 */
	public function sync(bool $allowSongsWithoutFilesInNc = false, bool $forceClientUpload = true): ?array {
		$libraryFolder = $this->getLibraryFolder();
		$importStats = null;

		// 1. Upload any new or modified client physical files referenced in the client DB to Nextcloud
		if (is_file($this->clientDbPath)) {
			$clientPdo = $this->openSqlite($this->clientDbPath);
			$previouslySyncedFileMeta = [];
			if (MobileSheetsTableHelper::tableExists($clientPdo, '_nc_sync_metadata')) {
				$metaStmt = $clientPdo->query("SELECT client_id, updated_at FROM _nc_sync_metadata WHERE entity_type = 'file'");
				if ($metaStmt !== false) {
					while ($mRow = $metaStmt->fetch()) {
						$previouslySyncedFileMeta[(int)$mRow['client_id']] = (int)($mRow['updated_at'] ?? 0);
					}
				}
			}

			if (MobileSheetsTableHelper::tableExists($clientPdo, 'Files')) {
				$stmt = $clientPdo->query('SELECT Id, Path, LastModified FROM Files');
				if ($stmt !== false) {
					while ($row = $stmt->fetch()) {
						$fileClientId = (int)($row['Id'] ?? 0);
						$fileLastModSec = (int)floor(((int)($row['LastModified'] ?? 0)) / 1000);
						$relPath = $this->extractLibraryRelativePath((string)($row['Path'] ?? ''));
						if ($relPath === '') {
							continue;
						}
						$localFile = $this->clientFilesDir . '/' . $relPath;
						if (!is_file($localFile)) {
							continue;
						}
						$content = (string)file_get_contents($localFile);
						if (!$libraryFolder->nodeExists($relPath)) {
							// Only upload if this file was newly created or updated on the client (not previously synced and deleted in Nextcloud)
							if (!isset($previouslySyncedFileMeta[$fileClientId]) || $fileLastModSec > $previouslySyncedFileMeta[$fileClientId]) {
								$this->ensureNextcloudSubfolders($libraryFolder, $relPath);
								$ncFile = $libraryFolder->newFile($relPath, $content);
								$this->fileEventListener->onNodeCreated($ncFile);
							}
						} elseif (!isset($previouslySyncedFileMeta[$fileClientId]) || $fileLastModSec > $previouslySyncedFileMeta[$fileClientId]) {
							// Only overwrite Nextcloud physical file if the file was modified on the client since baseline
							$ncNode = $libraryFolder->get($relPath);
							if ($ncNode instanceof File && $ncNode->getContent() !== $content) {
								$ncNode->putContent($content);
								$this->fileEventListener->onNodeWritten($ncNode);
							}
						}
					}
				}
			}

			// 2. Import client's mobilesheets.db into Nextcloud (simulating WebDAV PUT mobilesheets.db)
			if ($this->clientDbDirty || $forceClientUpload) {
				$importStats = $this->importer->importFromFile($this->libraryId, $this->clientDbPath, $this->user);
			}
		}

		// 3. Export authoritative mobilesheets.db and mobilesheets_hashcodes.txt from Nextcloud
		//    (simulating WebDAV GET mobilesheets.db and GET mobilesheets_hashcodes.txt)
		$this->exporter->exportToFile($this->libraryId, $this->ncExportedDbPath, $this->user);
		$exportedBytes = (string)file_get_contents($this->ncExportedDbPath);
		file_put_contents($this->clientDbPath, $exportedBytes);

		if ($libraryFolder->nodeExists('mobilesheets.db')) {
			$dbNode = $libraryFolder->get('mobilesheets.db');
			if ($dbNode instanceof File) {
				$dbNode->putContent($exportedBytes);
			}
		} else {
			$libraryFolder->newFile('mobilesheets.db', $exportedBytes);
		}

		$hashCodesContent = $this->exporter->generateHashcodes($this->libraryId);
		file_put_contents($this->clientHashCodesPath, $hashCodesContent);
		if ($libraryFolder->nodeExists('mobilesheets_hashcodes.txt')) {
			$hcNode = $libraryFolder->get('mobilesheets_hashcodes.txt');
			if ($hcNode instanceof File) {
				$hcNode->putContent($hashCodesContent);
			}
		} else {
			$libraryFolder->newFile('mobilesheets_hashcodes.txt', $hashCodesContent);
		}

		// 4. Sync physical files from Nextcloud library folder to client's local folder
		$this->syncPhysicalFilesFromNextcloudToClient($libraryFolder);

		$this->clientDbDirty = false;

		// 5. Verify conflict-free state and zero diff after every sync
		$this->assertConflictFreeAndNoDiff($allowSongsWithoutFilesInNc);

		return $importStats;
	}

	/**
	 * Assert that after syncing:
	 * 1. `mobilesheets.db` passes SQLite integrity check and has zero broken foreign keys or orphan rows.
	 * 2. Every file in `mobilesheets.db` exists physically in Nextcloud and on the client, and matches `mobilesheets_hashcodes.txt`.
	 * 3. `LibraryIntegrityService::checkIntegrity()` reports no conflicts.
	 * 4. Exporting `mobilesheets.db` and `mobilesheets_hashcodes.txt` from Nextcloud produces zero diff against the client's `mobilesheets.db` and `mobilesheets_hashcodes.txt`.
	 * 5. Re-importing the client's `mobilesheets.db` and re-exporting is completely idempotent (zero diff).
	 */
	public function assertConflictFreeAndNoDiff(bool $allowSongsWithoutFilesInNc = false): void {
		Assert::assertFileExists($this->clientDbPath, 'Client mobilesheets.db must exist');
		Assert::assertFileExists($this->clientHashCodesPath, 'Client mobilesheets_hashcodes.txt must exist');

		$clientPdo = $this->openSqlite($this->clientDbPath);

		// 1. SQLite physical integrity check
		$integrityCheck = (string)$clientPdo->query('PRAGMA integrity_check')->fetchColumn();
		Assert::assertSame('ok', strtolower($integrityCheck), 'mobilesheets.db PRAGMA integrity_check must be ok');

		// 2. Referential integrity in mobilesheets.db (no orphan Files, SongNotes, junction links, or sync metadata)
		$orphanFiles = (int)$clientPdo->query(
			'SELECT COUNT(*) FROM Files WHERE SongId NOT IN (SELECT Id FROM Songs)'
		)->fetchColumn();
		Assert::assertSame(0, $orphanFiles, 'mobilesheets.db must have no orphan rows in Files');

		$songsWithoutFilesInClientDb = (int)$clientPdo->query(
			'SELECT COUNT(*) FROM Songs WHERE Id NOT IN (SELECT DISTINCT SongId FROM Files)'
		)->fetchColumn();
		Assert::assertSame(
			0,
			$songsWithoutFilesInClientDb,
			'mobilesheets.db must not contain any songs without files'
		);

		if (MobileSheetsTableHelper::tableExists($clientPdo, 'SongNotes')) {
			$orphanNotes = (int)$clientPdo->query(
				'SELECT COUNT(*) FROM SongNotes WHERE SongId NOT IN (SELECT Id FROM Songs)'
			)->fetchColumn();
			Assert::assertSame(0, $orphanNotes, 'mobilesheets.db must have no orphan rows in SongNotes');
		}

		foreach (MobileSheetsEntityConfig::BY_TABLE as $entityTable => $cfg) {
			$linkTable = $cfg['linkTable'];
			$linkCol = $cfg['linkCol'];
			if (!MobileSheetsTableHelper::tableExists($clientPdo, $linkTable)) {
				continue;
			}
			$orphanSongLinks = (int)$clientPdo->query(
				'SELECT COUNT(*) FROM "' . str_replace('"', '""', $linkTable) . '" WHERE SongId NOT IN (SELECT Id FROM Songs)'
			)->fetchColumn();
			Assert::assertSame(0, $orphanSongLinks, "mobilesheets.db must have no orphan SongId links in {$linkTable}");

			if (MobileSheetsTableHelper::tableExists($clientPdo, $entityTable)) {
				$orphanEntityLinks = (int)$clientPdo->query(
					'SELECT COUNT(*) FROM "' . str_replace('"', '""', $linkTable) . '" WHERE "' . str_replace('"', '""', $linkCol) . '" NOT IN (SELECT Id FROM "' . str_replace('"', '""', $entityTable) . '")'
				)->fetchColumn();
				Assert::assertSame(0, $orphanEntityLinks, "mobilesheets.db must have no orphan {$linkCol} links in {$linkTable}");
			}
		}

		// 3. Verify _nc_sync_metadata consistency
		Assert::assertTrue(
			MobileSheetsTableHelper::tableExists($clientPdo, '_nc_sync_metadata'),
			'mobilesheets.db must contain _nc_sync_metadata table'
		);
		$metaSongCount = (int)$clientPdo->query("SELECT COUNT(*) FROM _nc_sync_metadata WHERE entity_type IN ('song', 'song_movement')")->fetchColumn();
		$dbSongCount = (int)$clientPdo->query('SELECT COUNT(*) FROM Songs')->fetchColumn();
		Assert::assertSame($dbSongCount, $metaSongCount, '_nc_sync_metadata song count must match Songs count');

		$movementCount = (int)$clientPdo->query("SELECT COUNT(*) FROM _nc_sync_metadata WHERE entity_type = 'song_movement'")->fetchColumn();
		$metaFileCount = (int)$clientPdo->query("SELECT COUNT(*) FROM _nc_sync_metadata WHERE entity_type = 'file'")->fetchColumn();
		$dbFileCount = (int)$clientPdo->query('SELECT COUNT(*) FROM Files')->fetchColumn();
		Assert::assertSame($dbFileCount, $metaFileCount + $movementCount, '_nc_sync_metadata file count + movement count must match Files count');

		// 4. Verify physical files & mobilesheets_hashcodes.txt match Files table
		$libraryFolder = $this->getLibraryFolder();
		$hashCodesRaw = (string)file_get_contents($this->clientHashCodesPath);
		$parsedHashCodes = MobileSheetsHashCodesFile::parse($this->clientHashCodesPath, $this->logger);
		$hashMapByBasename = [];
		foreach ($parsedHashCodes->entries() as $entry) {
			$relFromLib = $this->extractLibraryRelativePath($entry->path);
			$hashMapByBasename[$relFromLib] = $entry;
		}

		$filesStmt = $clientPdo->query('SELECT * FROM Files ORDER BY Id ASC');
		$referencedPaths = [];
		if ($filesStmt !== false) {
			while ($fRow = $filesStmt->fetch()) {
				$relPath = $this->extractLibraryRelativePath((string)$fRow['Path']);
				$referencedPaths[$relPath] = true;
				Assert::assertTrue(
					$libraryFolder->nodeExists($relPath),
					"File '{$relPath}' referenced in mobilesheets.db must exist in Nextcloud library folder"
				);
				Assert::assertFileExists(
					$this->clientFilesDir . '/' . $relPath,
					"File '{$relPath}' referenced in mobilesheets.db must exist in client local folder"
				);
				Assert::assertArrayHasKey(
					$relPath,
					$hashMapByBasename,
					"File '{$relPath}' referenced in mobilesheets.db must exist in mobilesheets_hashcodes.txt"
				);
				$ncFileNode = $libraryFolder->get($relPath);
				Assert::assertInstanceOf(File::class, $ncFileNode);
				$expectedHash = MobileSheetsPdf::contentHash((string)$ncFileNode->getContent());
				Assert::assertSame(
					(string)$expectedHash,
					$hashMapByBasename[$relPath]->hashCode,
					"Hash in mobilesheets_hashcodes.txt for '{$relPath}' must match physical file content hash"
				);
			}
		}
		Assert::assertSame(
			count($referencedPaths),
			count($parsedHashCodes->entries()),
			'mobilesheets_hashcodes.txt entry count must match distinct physical files in library'
		);

		// 5. Verify LibraryIntegrityService::checkIntegrity reports no conflicts
		$integrityIssues = $this->integrityService->checkIntegrity($this->libraryId, $this->userId);
		if ($allowSongsWithoutFilesInNc) {
			$integrityIssues = array_values(array_filter(
				$integrityIssues,
				static fn (IntegrityIssue $issue): bool => $issue->type !== IntegrityIssue::TYPE_SONG_WITHOUT_FILES
			));
		}
		Assert::assertSame(
			[],
			$integrityIssues,
			'LibraryIntegrityService::checkIntegrity must report zero conflicts, got: ' . json_encode($integrityIssues)
		);

		// 6. Export a fresh mobilesheets.db and hashcodes from Nextcloud and assert zero diff
		$freshExportPath = $this->tempDir . '/verify_export_' . bin2hex(random_bytes(4)) . '.db';
		try {
			$this->exporter->exportToFile($this->libraryId, $freshExportPath, $this->user);
			self::assertSqliteDatabasesHaveNoDiff($this->clientDbPath, $freshExportPath);

			$freshHashCodes = $this->exporter->generateHashcodes($this->libraryId);
			Assert::assertSame(
				$hashCodesRaw,
				$freshHashCodes,
				'Client mobilesheets_hashcodes.txt must have zero diff with Nextcloud mobilesheets_hashcodes.txt'
			);

			// 7. Re-import client DB and re-export to verify idempotency (zero diff on re-sync)
			$reimportStats = $this->importer->importFromFile($this->libraryId, $this->clientDbPath, $this->user);
			Assert::assertSame(0, $reimportStats['songsCreated'], 'Idempotent re-import must not create songs');
			Assert::assertSame(0, $reimportStats['songsDeleted'], 'Idempotent re-import must not delete songs');
			Assert::assertSame(0, $reimportStats['entitiesCreated'], 'Idempotent re-import must not create entities');
			Assert::assertSame(0, $reimportStats['entitiesDeleted'], 'Idempotent re-import must not delete entities');
			Assert::assertSame(0, $reimportStats['filesCreated'], 'Idempotent re-import must not create files');
			Assert::assertSame(0, $reimportStats['filesDeleted'], 'Idempotent re-import must not delete files');

			$reexportPath = $this->tempDir . '/verify_reexport_' . bin2hex(random_bytes(4)) . '.db';
			try {
				$this->exporter->exportToFile($this->libraryId, $reexportPath, $this->user);
				self::assertSqliteDatabasesHaveNoDiff($this->clientDbPath, $reexportPath);
			} finally {
				@unlink($reexportPath);
			}
		} finally {
			@unlink($freshExportPath);
		}
	}

	/**
	 * Compare two SQLite databases table-by-table, column-by-column, and row-by-row
	 * (including `_nc_sync_metadata`) and assert there is zero diff.
	 */
	public static function assertSqliteDatabasesHaveNoDiff(string $expectedDbPath, string $actualDbPath): void {
		$pdoExpected = new PDO('sqlite:' . $expectedDbPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$pdoActual = new PDO('sqlite:' . $actualDbPath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$expectedTables = $pdoExpected->query(
			"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC"
		)->fetchAll(PDO::FETCH_COLUMN);
		$actualTables = $pdoActual->query(
			"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC"
		)->fetchAll(PDO::FETCH_COLUMN);

		Assert::assertSame($expectedTables, $actualTables, 'Table list must be identical between client and Nextcloud mobilesheets.db');

		foreach ($expectedTables as $table) {
			$quoted = '"' . str_replace('"', '""', (string)$table) . '"';
			$expectedCols = array_column($pdoExpected->query("PRAGMA table_info({$quoted})")->fetchAll(), 'name');
			$actualCols = array_column($pdoActual->query("PRAGMA table_info({$quoted})")->fetchAll(), 'name');
			Assert::assertSame($expectedCols, $actualCols, "Columns in table {$table} must be identical");

			$orderBy = '';
			if (in_array('Id', $expectedCols, true)) {
				$orderBy = ' ORDER BY "Id" ASC';
			} elseif (in_array('entity_type', $expectedCols, true) && in_array('client_id', $expectedCols, true)) {
				$orderBy = ' ORDER BY "entity_type" ASC, "client_id" ASC';
			}

			$expectedRows = $pdoExpected->query("SELECT * FROM {$quoted}{$orderBy}")->fetchAll();
			$actualRows = $pdoActual->query("SELECT * FROM {$quoted}{$orderBy}")->fetchAll();

			Assert::assertSame(
				$expectedRows,
				$actualRows,
				"Rows in table {$table} must have zero diff between client and Nextcloud mobilesheets.db"
			);
		}
	}

	/**
	 * Read all rows from a table in the client's `mobilesheets.db`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getClientTableRows(string $tableName, string $orderBy = 'Id ASC'): array {
		$pdo = $this->openSqlite($this->clientDbPath);
		$quoted = '"' . str_replace('"', '""', $tableName) . '"';
		$orderClause = $orderBy !== '' ? ' ORDER BY ' . $orderBy : '';
		$stmt = $pdo->query("SELECT * FROM {$quoted}{$orderClause}");
		return $stmt !== false ? $stmt->fetchAll() : [];
	}

	public function getClientHashCodesContent(): string {
		return is_file($this->clientHashCodesPath) ? (string)file_get_contents($this->clientHashCodesPath) : '';
	}

	public function cleanup(): void {
		try {
			if ($this->libraryFolderName !== '') {
				$userFolder = $this->rootFolder->getUserFolder($this->userId);
				if ($userFolder->nodeExists($this->libraryFolderName)) {
					$userFolder->get($this->libraryFolderName)->delete();
				}
			}
		} catch (\Throwable) {
		}

		$tables = [
			'sheetmusic_song_entities',
			'sheetmusic_song_files',
			'sheetmusic_song_attributes',
			'sheetmusic_misc_data',
			'sheetmusic_misc_schema',
			'sheetmusic_songs',
			'sheetmusic_entities',
			'sheetmusic_libraries',
		];
		foreach ($tables as $table) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)->executeStatement();
			} catch (\Throwable) {
			}
		}

		$this->removeDirectoryRecursive($this->tempDir);
	}

	public function openSqlite(string $path): PDO {
		return new PDO('sqlite:' . $path, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}

	private function ensureNextcloudSubfolders(Folder $baseFolder, string $relativePath): void {
		$dir = dirname($relativePath);
		if ($dir === '' || $dir === '.' || $dir === '/') {
			return;
		}
		$current = '';
		foreach (explode('/', $dir) as $part) {
			if ($part === '') {
				continue;
			}
			$current = $current === '' ? $part : $current . '/' . $part;
			if (!$baseFolder->nodeExists($current)) {
				$baseFolder->newFolder($current);
			}
		}
	}

	public function extractLibraryRelativePath(string $storedOrRelativePath): string {
		$path = ltrim(rawurldecode($storedOrRelativePath), '/');
		$davPrefix = ltrim(MobileSheetsPathResolver::WEBDAV_ROOT, '/') . '/' . $this->userId . '/';
		if (str_starts_with($path, $davPrefix)) {
			$path = substr($path, strlen($davPrefix));
		}
		if ($this->libraryFolderName !== '' && str_starts_with($path, $this->libraryFolderName . '/')) {
			$path = substr($path, strlen($this->libraryFolderName) + 1);
		}
		return ltrim($path, '/');
	}

	private function syncPhysicalFilesFromNextcloudToClient(Folder $libraryFolder): void {
		$pdo = $this->openSqlite($this->clientDbPath);
		$expectedClientPaths = [];
		if (MobileSheetsTableHelper::tableExists($pdo, 'Files')) {
			$stmt = $pdo->query('SELECT Path FROM Files');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$relPath = $this->extractLibraryRelativePath((string)($row['Path'] ?? ''));
					if ($relPath !== '') {
						$expectedClientPaths[$relPath] = true;
						if ($libraryFolder->nodeExists($relPath)) {
							$node = $libraryFolder->get($relPath);
							if ($node instanceof File) {
								$dest = $this->clientFilesDir . '/' . $relPath;
								$destDir = dirname($dest);
								if (!is_dir($destDir)) {
									mkdir($destDir, 0777, true);
								}
								file_put_contents($dest, (string)$node->getContent());
							}
						}
					}
				}
			}
		}

		// Prune client files no longer in the library
		if (is_dir($this->clientFilesDir)) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($this->clientFilesDir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($it as $fileInfo) {
				if ($fileInfo->isFile()) {
					$rel = ltrim(substr($fileInfo->getPathname(), strlen($this->clientFilesDir)), '/');
					if (!isset($expectedClientPaths[$rel])) {
						@unlink($fileInfo->getPathname());
					}
				}
			}
		}
	}

	private function removeDirectoryRecursive(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $item) {
			if ($item->isDir()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($dir);
	}
}
