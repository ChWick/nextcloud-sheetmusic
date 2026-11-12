<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class LibraryService {
	public function __construct(
		private LibraryMapper $libraryMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private ?MobileSheetsSqliteExporter $exporter = null,
		private ?MobileSheetsSqliteImporter $importer = null,
		private ?LibraryReconciler $reconciler = null,
		private ?ImportProgressService $progressService = null,
		private ?LibraryShareService $shareService = null,
	) {
	}

	public function resolveLibraryPath(string $userId, int $libraryId): ?string {
		$library = $this->getAccessibleLibrary($userId, $libraryId);
		if ($library === null) {
			return null;
		}

		return $library->path;
	}

	public function getAccessibleLibrary(string $userId, int $libraryId): ?InternalLibrary {
		if ($this->shareService !== null) {
			return $this->shareService->getAccessibleLibrary($userId, $libraryId);
		}
		return $this->libraryMapper->findForUser($userId, $libraryId);
	}

	public function canReadLibrary(string $userId, int|InternalLibrary $library): bool {
		if ($this->shareService !== null) {
			return $this->shareService->canRead($userId, $library);
		}
		$lib = is_int($library) ? $this->libraryMapper->findById($library) : $library;
		return $lib !== null && $lib->userId === $userId;
	}

	public function canWriteLibrary(string $userId, int|InternalLibrary $library): bool {
		if ($this->shareService !== null) {
			return $this->shareService->canWrite($userId, $library);
		}
		$lib = is_int($library) ? $this->libraryMapper->findById($library) : $library;
		return $lib !== null && $lib->userId === $userId;
	}

	public function isLibraryOwner(string $userId, int|InternalLibrary $library): bool {
		if ($this->shareService !== null) {
			return $this->shareService->isOwner($userId, $library);
		}
		$lib = is_int($library) ? $this->libraryMapper->findById($library) : $library;
		return $lib !== null && $lib->userId === $userId;
	}

	public function getShareService(): ?LibraryShareService {
		return $this->shareService;
	}

	public function getTargetLibraryId(int $libraryId): int {
		if ($this->shareService !== null) {
			return $this->shareService->getParentLibraryId($libraryId);
		}
		return $libraryId;
	}

	/**
	 * @return array{0: string, 1: string}|null array of [libraryPath, databasePath], or null if library is missing or invalid
	 */
	public function getLibraryAndDatabasePath(string $userId, int $libraryId): ?array {
		$libraryPath = $this->resolveLibraryPath($userId, $libraryId);
		if ($libraryPath === null) {
			return null;
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);

		// 1. If physical .db file exists at $libraryPath or within it as mobilesheets.db, use it
		$dbRel = str_ends_with(strtolower($libraryPath), '.db')
			? ltrim($libraryPath, '/')
			: ltrim(rtrim($libraryPath, '/') . '/mobilesheets.db', '/');

		try {
			if ($userFolder->nodeExists($dbRel)) {
				$node = $userFolder->get($dbRel);
				$localFile = $node->getStorage()->getLocalFile($node->getInternalPath());
				if ($localFile !== false) {
					return [$libraryPath, $localFile];
				}
			}
		} catch (\Throwable) {
			// Fall through to virtual export
		}

		// 2. Purely virtual library: generate/retrieve cached SQLite file
		$databasePath = $this->getVirtualDatabasePath($userId, $libraryId);
		if ($databasePath === null) {
			return null;
		}

		return [$libraryPath, $databasePath];
	}

	public function exportDatabaseToFile(int $libraryId, string $targetPath, IUser|string|null $user = null): void {
		$this->exporter?->exportToFile($libraryId, $targetPath, $user);
	}

	public function generateHashcodes(int $libraryId): string {
		return $this->exporter?->generateHashcodes($libraryId) ?? '';
	}

	public function getVirtualDatabasePath(string $userId, int $libraryId): ?string {
		if ($this->exporter === null) {
			return null;
		}

		$cacheFile = $this->getCacheFilePath($libraryId);
		$cacheDir = dirname($cacheFile);
		if (!is_dir($cacheDir)) {
			@mkdir($cacheDir, 0700, true);
		}

		$library = $this->libraryMapper->findById($libraryId);
		if ($library === null) {
			return null;
		}

		$updatedAt = $library->updatedAt;
		if (!file_exists($cacheFile) || filemtime($cacheFile) < $updatedAt) {
			$partPath = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.part';
			try {
				$this->exporter->exportToFile($libraryId, $partPath, $userId);
				rename($partPath, $cacheFile);
			} catch (\Throwable $e) {
				$this->logger->error('Failed to export virtual SQLite database', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'userId' => $userId,
				]);
				return null;
			} finally {
				if (file_exists($partPath)) {
					@unlink($partPath);
				}
			}
		}

		return $cacheFile;
	}

	public function getDatabasePath(string $userId, int $libraryId): ?string {
		$pair = $this->getLibraryAndDatabasePath($userId, $libraryId);
		return $pair === null ? null : $pair[1];
	}

	public function getLibraries(string $userId): array {
		if ($this->shareService !== null) {
			return $this->shareService->getAccessibleLibraries($userId);
		}
		return $this->libraryMapper->findAllForUser($userId);
	}

	public function addLibrary(
		string $userId,
		string $path,
		bool $importExistingDb = true,
		?string $progressId = null,
		bool $importPdfMetadata = true,
	): ?array {
		if (!str_starts_with($path, '/')) {
			return null;
		}

		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ($extension !== '' && $extension !== 'db') {
			return null;
		}

		$userFolder = null;
		$folderId = null;
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
		}

		if ($userFolder !== null) {
			$relPath = ltrim($path, '/');
			if ($userFolder->nodeExists($relPath)) {
				$node = $userFolder->get($relPath);
				if ($extension === '' && !($node instanceof \OCP\Files\Folder)) {
					throw new \InvalidArgumentException('Selected path is not a directory');
				}
				if ($extension !== '' && !($node instanceof \OCP\Files\File)) {
					throw new \InvalidArgumentException('Selected path is not a database file');
				}
				$folderNode = $node instanceof \OCP\Files\Folder ? $node : $node->getParent();
				$folderId = $folderNode?->getId();
			}
		}

		$targetDbRel = null;
		if ($extension === 'db') {
			$targetDbRel = ltrim($path, '/');
			$baseDir = dirname($path);
			$libraryPath = ($baseDir === '.' || $baseDir === '') ? '/' : $baseDir;
		} else {
			$libraryPath = $path;
		}
		if ($libraryPath !== '/' && str_ends_with($libraryPath, '/')) {
			$libraryPath = rtrim($libraryPath, '/');
		}

		if ($folderId === null && $userFolder !== null) {
			$cleanLibRel = ltrim($libraryPath, '/');
			try {
				if ($cleanLibRel === '') {
					$folderId = $userFolder->getId();
				} elseif ($userFolder->nodeExists($cleanLibRel)) {
					$folderId = $userFolder->get($cleanLibRel)->getId();
				}
			} catch (\Throwable) {
				// Ignore
			}
		}

		if ($progressId !== null && $this->progressService !== null) {
			$this->progressService->start($progressId, 'Initializing library...');
		}

		$isMobileSheets = ($extension === 'db');
		$progressCallback = function (string $stage, int $current, int $total, ?string $message = null) use ($progressId, $isMobileSheets): void {
			if ($progressId !== null && $this->progressService !== null) {
				$this->progressService->update($progressId, $stage, $current, $total, $message ?? '', $isMobileSheets);
			}
		};

		try {
			$this->libraryMapper->addForUser($userId, $libraryPath, $folderId);
			$libraries = $this->libraryMapper->findAllForUser($userId);
			$added = null;
			foreach ($libraries as $lib) {
				if ($lib->path === $libraryPath) {
					$added = $lib;
					break;
				}
			}

			if ($added !== null) {
				$libraryId = (int)$added->id;
				if ($importExistingDb) {
					$this->importExistingPhysicalDatabase($libraryId, $libraryPath, $path, $targetDbRel, $userId, $progressCallback);
				}
				$this->reconcileNewLibrary($libraryId, $userId, $progressCallback, $importPdfMetadata);
			}

			if ($progressId !== null && $this->progressService !== null) {
				$this->progressService->complete($progressId, 'Import complete');
			}

			return ['path' => $libraryPath];
		} catch (\Throwable $e) {
			if ($progressId !== null && $this->progressService !== null) {
				$this->progressService->fail($progressId, $e->getMessage());
			}
			throw $e;
		}
	}

	public function getProgress(string $progressId): ?array {
		return $this->progressService?->get($progressId);
	}

	public function startProgress(string $progressId, string $message = ''): void {
		$this->progressService?->start($progressId, $message);
	}

	public function updateProgress(string $progressId, string $stage, int $current, int $total, string $detail = '', bool $isMobileSheets = false): void {
		$this->progressService?->update($progressId, $stage, $current, $total, $detail, $isMobileSheets);
	}

	public function completeProgress(string $progressId, string $message = 'Completed'): void {
		$this->progressService?->complete($progressId, $message);
	}

	public function failProgress(string $progressId, string $errorMessage): void {
		$this->progressService?->fail($progressId, $errorMessage);
	}

	public function deleteLibrary(string $userId, int $id): ?array {
		$lib = $this->libraryMapper->findById($id);
		if ($lib === null) {
			return null;
		}
		if (!$this->isLibraryOwner($userId, $lib)) {
			throw new \OCP\Security\SecurityException('Only the library owner can delete the library');
		}

		$libraryPath = $lib->path;
		$this->libraryMapper->deleteForUser($userId, $id);

		$cacheFile = $this->getCacheFilePath($id);
		if (file_exists($cacheFile)) {
			@unlink($cacheFile);
		}
		$legacyCache = sys_get_temp_dir() . '/sheetmusic_cache/lib_' . $id . '.db';
		if (file_exists($legacyCache) && is_writable($legacyCache)) {
			@unlink($legacyCache);
		}

		return [
			'id' => $id,
			'path' => $libraryPath,
		];
	}

	private function getCacheFilePath(int $libraryId): string {
		$owner = function_exists('posix_geteuid') ? (string)posix_geteuid() : (string)getmyuid();
		return sys_get_temp_dir() . '/sheetmusic_cache_' . $owner . '/lib_' . $libraryId . '.db';
	}

	private function importExistingPhysicalDatabase(
		int $libraryId,
		string $libraryPath,
		string $path,
		?string $targetDbRel,
		string $userId,
		callable $progressCallback,
	): void {
		if ($this->importer === null) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$dbRel = $targetDbRel ?? ltrim(rtrim($libraryPath, '/') . '/mobilesheets.db', '/');

			if ($userFolder->nodeExists($dbRel)) {
				$node = $userFolder->get($dbRel);
				$localFile = $node->getStorage()->getLocalFile($node->getInternalPath());
				if ($localFile !== false && file_exists($localFile)) {
					$this->importer->importFromFile($libraryId, $localFile, $userId, $progressCallback);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to import existing physical MobileSheets db', [
				'exception' => $e,
				'path' => $path,
				'userId' => $userId,
			]);
		}
	}

	private function reconcileNewLibrary(
		int $libraryId,
		string $userId,
		callable $progressCallback,
		bool $importPdfMetadata = false,
	): void {
		if ($this->reconciler === null) {
			return;
		}

		try {
			$this->reconciler->reconcile($libraryId, $progressCallback, $importPdfMetadata);
		} catch (\Throwable $e) {
			$this->logger->warning('Initial library reconciliation failed', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'userId' => $userId,
			]);
		}
	}

	public function isAutoPathsEnabled(string $userId, int $libraryId): bool {
		$library = $this->libraryMapper->findForUser($userId, $libraryId);
		return $library?->autoPaths ?? true;
	}

	public function isAutoPdfMetadataEnabled(string $userId, int $libraryId): bool {
		$library = $this->libraryMapper->findForUser($userId, $libraryId);
		return $library?->autoPdfMetadata ?? true;
	}

	public function getNamingRulePreset(string $userId, int $libraryId): string {
		$targetLibraryId = $this->getTargetLibraryId($libraryId);
		$library = $this->libraryMapper->findById($targetLibraryId);
		return $library?->namingRulePreset ?? SongPathNamingRule::PRESET_AUTO;
	}

	public function updateSettings(
		string $userId,
		int $libraryId,
		?bool $autoPaths = null,
		?bool $autoPdfMetadata = null,
		?string $namingRulePreset = null,
	): ?InternalLibrary {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null) {
			return null;
		}
		if (!$this->isLibraryOwner($userId, $lib)) {
			throw new \OCP\Security\SecurityException('Only the library owner can update library settings');
		}
		if ($namingRulePreset !== null && !in_array($namingRulePreset, SongPathNamingRule::PRESETS, true)) {
			throw new \InvalidArgumentException('Invalid naming rule preset: ' . $namingRulePreset);
		}
		$this->libraryMapper->setSettingsForUser($userId, $libraryId, $autoPaths, $autoPdfMetadata, $namingRulePreset);
		return $this->libraryMapper->findForUser($userId, $libraryId);
	}
}
