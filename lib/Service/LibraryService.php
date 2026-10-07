<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class LibraryService {
	public function __construct(
		private LibraryMapper $libraryMapper,
		private SongMapper $songMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
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

		try {
			$node = $this->rootFolder->getUserFolder($userId)->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return null;
			}

			return [$libraryPath, $databasePath];
		} catch (\Throwable $e) {
			$this->logger->error('Could not resolve database path for MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
				'userId' => $userId,
			]);

			return null;
		}
	}

	public function getDatabasePath(string $userId, int $libraryId): ?string {
		$pair = $this->getLibraryAndDatabasePath($userId, $libraryId);
		return $pair === null ? null : $pair[1];
	}

	public function getLibraries(string $userId): array {
		return $this->libraryMapper->findAllForUser($userId);
	}

	public function addLibrary(string $userId, string $path): ?array {
		if (!str_starts_with($path, '/') || !str_ends_with(strtolower($path), '.db')) {
			return null;
		}

		$this->libraryMapper->addForUser($userId, $path);
		return ['path' => $path];
	}

	public function deleteLibrary(string $userId, int $id): ?array {
		$libraryPath = $this->resolveLibraryPath($userId, $id);
		if ($libraryPath === null) {
			return null;
		}

		$this->libraryMapper->deleteForUser($userId, $id);
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

	/**
	 * @return array{id: int, path: string, autoPaths: bool}|null
	 */
	public function updateSettings(string $userId, int $libraryId, bool $autoPaths): ?array {
		$this->libraryMapper->setAutoPathsForUser($userId, $libraryId, $autoPaths);
		return $this->libraryMapper->findForUser($userId, $libraryId);
	}

}
