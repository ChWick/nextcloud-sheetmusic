<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\LibraryMapper;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class LibraryService {
	public function __construct(
		private LibraryMapper $libraryMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private ?MobileSheetsSqliteExporter $exporter = null,
		private ?MobileSheetsSqliteImporter $importer = null,
		private ?LibraryReconciler $reconciler = null,
	) {
	}

	public function resolveLibraryPath(string $userId, int $libraryId): ?string {
		$library = $this->libraryMapper->findForUser($userId, $libraryId);
		if ($library === null) {
			return null;
		}

		return (string)($library['path'] ?? '');
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

		// 1. If physical .db file exists at $libraryPath, use it
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
		}

		// 2. Purely virtual library: generate/retrieve cached SQLite file
		$databasePath = $this->getVirtualDatabasePath($userId, $libraryId);
		if ($databasePath === null) {
			return null;
		}

		return [$libraryPath, $databasePath];
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

		$updatedAt = (int)($library['updated_at'] ?? 0);
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

	public function syncFromDatabase(string $userId, int $libraryId): void {
		if ($this->importer === null) {
			return;
		}

		$databasePath = $this->getDatabasePath($userId, $libraryId);
		if ($databasePath !== null && file_exists($databasePath)) {
			try {
				$this->importer->importFromFile($libraryId, $databasePath, $userId);
			} catch (\Throwable $e) {
				$this->logger->error('Failed to sync changes from SQLite database', [
					'exception' => $e,
					'libraryId' => $libraryId,
					'userId' => $userId,
				]);
			}
		}
	}

	public function getDatabasePath(string $userId, int $libraryId): ?string {
		$pair = $this->getLibraryAndDatabasePath($userId, $libraryId);
		return $pair === null ? null : $pair[1];
	}

	public function getLibraries(string $userId): array {
		return $this->libraryMapper->findAllForUser($userId);
	}

	public function addLibrary(string $userId, string $path, bool $importExistingDb = true): ?array {
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
					if (count($node->getDirectoryListing()) > 0) {
						throw new \InvalidArgumentException('The directory must be empty');
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

		$this->libraryMapper->addForUser($userId, $path);
		$libraries = $this->libraryMapper->findAllForUser($userId);
		$added = null;
		foreach ($libraries as $lib) {
			if ($lib['path'] === $path) {
				$added = $lib;
				break;
			}
		}

		if ($added !== null) {
			$libraryId = (int)$added['id'];

			// Check for existing physical db file to import if requested
			if ($importExistingDb && $this->importer !== null) {
				try {
					$userFolder = $this->rootFolder->getUserFolder($userId);
					$targetDbRel = str_ends_with(strtolower($path), '.db')
						? ltrim($path, '/')
						: ltrim(rtrim($path, '/') . '/mobilesheets.db', '/');

					if ($userFolder->nodeExists($targetDbRel)) {
						$node = $userFolder->get($targetDbRel);
						$localFile = $node->getStorage()->getLocalFile($node->getInternalPath());
						if ($localFile !== false && file_exists($localFile)) {
							$this->importer->importFromFile($libraryId, $localFile, $userId);
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
					$this->reconciler->reconcile($libraryId);
				} catch (\Throwable $e) {
					$this->logger->warning('Initial library reconciliation failed', [
						'exception' => $e,
						'libraryId' => $libraryId,
						'userId' => $userId,
					]);
				}
			}
		}

		return ['path' => $path];
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
		if ($library === null) {
			return true;
		}

		return LibraryMapper::autoPathsOf($library);
	}

	public function isAutoPdfMetadataEnabled(string $userId, int $libraryId): bool {
		$library = $this->libraryMapper->findForUser($userId, $libraryId);
		if ($library === null) {
			return true;
		}

		return LibraryMapper::autoPdfMetadataOf($library);
	}

	/**
	 * @return array{id: int, path: string, autoPaths: bool, autoPdfMetadata: bool}|null
	 */
	public function updateSettings(
		string $userId,
		int $libraryId,
		?bool $autoPaths = null,
		?bool $autoPdfMetadata = null,
	): ?array {
		$this->libraryMapper->setSettingsForUser($userId, $libraryId, $autoPaths, $autoPdfMetadata);
		return $this->libraryMapper->findForUser($userId, $libraryId);
	}
}
