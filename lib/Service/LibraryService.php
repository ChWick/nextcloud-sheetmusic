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
	) {
	}

	public function resolveLibraryPath(string $userId, int $libraryId): ?string {
		$library = $this->libraryMapper->findForUser($userId, $libraryId);
		if ($library === null) {
			return null;
		}

		return $library->path;
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
		if (str_ends_with(strtolower($libraryPath), '.db')) {
			try {
				$node = $userFolder->get(ltrim($libraryPath, '/'));
				$localFile = $node->getStorage()->getLocalFile($node->getInternalPath());
				if ($localFile !== false) {
					return [$libraryPath, $localFile];
				}
			} catch (\Throwable $e) {
				// Fall through to virtual export
			}
		} else {
			try {
				$dbRel = ltrim(rtrim($libraryPath, '/') . '/mobilesheets.db', '/');
				if ($userFolder->nodeExists($dbRel)) {
					$node = $userFolder->get($dbRel);
					$localFile = $node->getStorage()->getLocalFile($node->getInternalPath());
					if ($localFile !== false) {
						return [$libraryPath, $localFile];
					}
				}
			} catch (\Throwable $e) {
				// Fall through to virtual export
			}
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

		$cacheDir = sys_get_temp_dir() . '/sheetmusic_cache';
		if (!is_dir($cacheDir)) {
			@mkdir($cacheDir, 0777, true);
		}

		$cacheFile = $cacheDir . '/lib_' . $libraryId . '.db';
		$library = $this->libraryMapper->findById($libraryId);
		if ($library === null) {
			return null;
		}

		$updatedAt = $library->updatedAt;
		if (!file_exists($cacheFile) || filemtime($cacheFile) < $updatedAt) {
			try {
				$this->exporter->exportToFile($libraryId, $cacheFile, $userId);
			} catch (\Throwable $e) {
				$this->logger->error('Failed to export virtual SQLite database', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'userId' => $userId,
				]);
				return null;
			}
		}

		return $cacheFile;
	}

	public function getDatabasePath(string $userId, int $libraryId): ?string {
		$pair = $this->getLibraryAndDatabasePath($userId, $libraryId);
		return $pair === null ? null : $pair[1];
	}

	public function getLibraries(string $userId): array {
		return $this->libraryMapper->findAllForUser($userId);
	}

	public function addLibrary(string $userId, string $path, bool $importExistingDb = true, ?string $progressId = null): ?array {
		if (!str_starts_with($path, '/')) {
			return null;
		}

		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ($extension !== '' && $extension !== 'db') {
			return null;
		}

		$userFolder = null;
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
		}

		if ($userFolder !== null) {
			$relPath = ltrim($path, '/');
			if ($extension === '') {
				if ($userFolder->nodeExists($relPath)) {
					$node = $userFolder->get($relPath);
					if (!($node instanceof \OCP\Files\Folder)) {
						throw new \InvalidArgumentException('Selected path is not a directory');
					}
				}
			} else {
				if ($userFolder->nodeExists($relPath)) {
					$node = $userFolder->get($relPath);
					if (!($node instanceof \OCP\Files\File)) {
						throw new \InvalidArgumentException('Selected path is not a database file');
					}
				}
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
			$this->libraryMapper->addForUser($userId, $libraryPath);
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

				// Check for existing physical db file to import if requested
				if ($importExistingDb && $this->importer !== null) {
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

				// Run initial folder reconciliation to register music files
				if ($this->reconciler !== null) {
					try {
						$this->reconciler->reconcile($libraryId, $progressCallback);
					} catch (\Throwable $e) {
						$this->logger->warning('Initial library reconciliation failed', [
							'exception' => $e,
							'libraryId' => $libraryId,
							'userId' => $userId,
						]);
					}
				}
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
		$libraryPath = $this->resolveLibraryPath($userId, $id);
		if ($libraryPath === null) {
			return null;
		}

		$this->libraryMapper->deleteForUser($userId, $id);

		$cacheFile = sys_get_temp_dir() . '/sheetmusic_cache/lib_' . $id . '.db';
		if (file_exists($cacheFile)) {
			@unlink($cacheFile);
		}

		return [
			'id' => $id,
			'path' => $libraryPath,
		];
	}

	public function isAutoPathsEnabled(string $userId, int $libraryId): bool {
		$library = $this->libraryMapper->findForUser($userId, $libraryId);
		return $library?->autoPaths ?? true;
	}

	public function isAutoPdfMetadataEnabled(string $userId, int $libraryId): bool {
		$library = $this->libraryMapper->findForUser($userId, $libraryId);
		return $library?->autoPdfMetadata ?? true;
	}

	public function updateSettings(
		string $userId,
		int $libraryId,
		?bool $autoPaths = null,
		?bool $autoPdfMetadata = null,
	): ?InternalLibrary {
		$this->libraryMapper->setSettingsForUser($userId, $libraryId, $autoPaths, $autoPdfMetadata);
		return $this->libraryMapper->findForUser($userId, $libraryId);
	}
}
