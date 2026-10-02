<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

class LibraryIntegrityService {
	public function __construct(
		private LibraryService $libraryService,
		private SongMapper $songMapper,
		private FileManager $fileManager,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Perform a full integrity audit on a MobileSheets library.
	 *
	 * Checks:
	 * 1. Missing files (database points to a file path that does not exist in Nextcloud).
	 * 2. Duplicate file paths (multiple database file records pointing to the exact same physical path).
	 * 3. Naming rule mismatches (files that deviate from the standard naming conventions).
	 * 4. Orphan files (files present in the library directory structure not referenced by any song).
	 *
	 * @return list<IntegrityIssue>
	 */
	public function checkIntegrity(int $libraryId, string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();
		['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);

		$songs = $this->songMapper->findSongs($databasePath);
		$targetFolderPath = (string) $targetFolder->getPath();

		$issues = [];
		$referencedRelativePaths = []; // relativeToTarget => list<array{songId: int, songTitle: string, fileId: int}>

		foreach ($songs as $song) {
			foreach ($song->files as $file) {
				$pathObj = $this->pathResolver->fromStored($file->path, $user);
				if ($pathObj === null) {
					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_MISSING_FILE,
						severity: IntegrityIssue::SEVERITY_ERROR,
						message: sprintf('File path "%s" sits outside the library folder.', $file->path),
						songId: $song->id,
						songTitle: $song->title,
						fileId: $file->id,
						path: $file->path,
						fixable: false,
					);
					continue;
				}

				// Compute path relative to the library target folder
				$internalPath = $pathObj->internal;
				$relativeToTarget = null;
				if ($internalPath === $targetFolderPath) {
					$relativeToTarget = '';
				} elseif (str_starts_with($internalPath, $targetFolderPath . '/')) {
					$relativeToTarget = substr($internalPath, strlen($targetFolderPath) + 1);
				}

				$sourceExists = ($relativeToTarget !== null && $targetFolder->nodeExists($relativeToTarget));

				// Calculate valid target paths (naming rules)
				$validTargetPaths = [];
				try {
					$validTargetPaths = createTargetPaths(
						$song,
						getFileExtension($file->path),
						fileIndexOf($song, $file),
						fileCountForExtension($song, $file),
					);
				} catch (NoNamingRuleException) {
					// Song missing composer, artist, or collection - keeps existing name
				}

				// Check if the expected target file already exists on disk
				$targetExists = false;
				if (!empty($validTargetPaths)) {
					foreach ($validTargetPaths as $validPath) {
						$cleanValid = ltrim($validPath, '/');
						if ($targetFolder->nodeExists($cleanValid)) {
							$targetExists = true;
							if (!$sourceExists) {
								// Mark target file as referenced by song so it is not flagged as an orphan
								$referencedRelativePaths[$cleanValid][] = [
									'songId' => $song->id,
									'songTitle' => $song->title,
									'fileId' => $file->id,
								];
							}
							break;
						}
					}
				}

				if ($sourceExists) {
					$referencedRelativePaths[$relativeToTarget][] = [
						'songId' => $song->id,
						'songTitle' => $song->title,
						'fileId' => $file->id,
					];

					// Check naming rule compliance
					$isCompliant = false;
					$normalizedRelative = strtolower(ltrim($relativeToTarget, '/'));
					foreach ($validTargetPaths as $validPath) {
						if ($normalizedRelative === strtolower(ltrim($validPath, '/'))) {
							$isCompliant = true;
							break;
						}
					}

					if (!$isCompliant) {
						$issues[] = new IntegrityIssue(
							type: IntegrityIssue::TYPE_INVALID_NAMING_RULE,
							severity: IntegrityIssue::SEVERITY_WARNING,
							message: sprintf('File path "%s" does not match standard naming rule.', basename($file->path)),
							songId: $song->id,
							songTitle: $song->title,
							fileId: $file->id,
							path: $relativeToTarget,
							suggestedPath: $validTargetPaths[0] ?? null,
							fixable: true,
						);
					}
				} else {
					// Source file does not exist at stored path.
					// If the target file ALREADY exists at the expected naming-rule path on disk:
					if ($targetExists) {
						$issues[] = new IntegrityIssue(
							type: IntegrityIssue::TYPE_INVALID_NAMING_RULE,
							severity: IntegrityIssue::SEVERITY_WARNING,
							message: sprintf('Database path "%s" is outdated; file already exists at the standard naming path.', basename($file->path)),
							songId: $song->id,
							songTitle: $song->title,
							fileId: $file->id,
							path: $relativeToTarget ?? $file->path,
							suggestedPath: $validTargetPaths[0] ?? null,
							fixable: true,
						);
					} else {
						// Genuinely missing file (neither source nor target exist on disk)
						$issues[] = new IntegrityIssue(
							type: IntegrityIssue::TYPE_MISSING_FILE,
							severity: IntegrityIssue::SEVERITY_ERROR,
							message: sprintf('File "%s" referenced by song "%s" does not exist in Nextcloud storage.', basename($file->path), $song->title),
							songId: $song->id,
							songTitle: $song->title,
							fileId: $file->id,
							path: $relativeToTarget ?? $file->path,
							fixable: false,
						);
					}
				}
			}
		}

		// Index songs by ID for duplicate checks
		$songsById = [];
		foreach ($songs as $s) {
			$songsById[$s->id] = $s;
		}

		// Check for duplicate physical file references
		foreach ($referencedRelativePaths as $relPath => $references) {
			if (count($references) > 1) {
				$songIds = array_values(array_unique(array_column($references, 'songId')));

				if (count($songIds) > 1) {
					$referencingSongs = array_values(array_filter(
						array_map(fn($id) => $songsById[$id] ?? null, $songIds)
					));

					$areExactDuplicates = $this->areSongsExactDuplicates($referencingSongs);
					$songTitles = array_map(fn($s) => sprintf('"%s" (ID %d)', $s->title, $s->id), $referencingSongs);

					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_DUPLICATE_FILE_PATH,
						severity: IntegrityIssue::SEVERITY_ERROR,
						message: sprintf(
							'Multiple database entries point to the same file path "%s" (%s).',
							basename($relPath),
							implode(', ', $songTitles)
						),
						songId: $referencingSongs[0]->id ?? $references[0]['songId'],
						songTitle: $referencingSongs[0]->title ?? $references[0]['songTitle'],
						fileId: $references[0]['fileId'],
						path: $relPath,
						fixable: $areExactDuplicates,
						details: [
							'songIds' => $songIds,
							'exactDuplicate' => $areExactDuplicates,
						],
					);
				}
			}
		}

		// Scan folder for orphan files (files in storage not in DB)
		$this->scanOrphans($targetFolder, $targetFolderPath, $referencedRelativePaths, $issues);

		return $issues;
	}

	/**
	 * Automatically resolve/fix a specific integrity issue.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function fixIssue(int $libraryId, string $userId, string $issueType, ?int $songId = null, ?int $fileId = null): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();

		if ($issueType === IntegrityIssue::TYPE_INVALID_NAMING_RULE && $songId !== null) {
			['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
			$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

			try {
				$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
				$hashCodes?->save();

				return ['success' => true, 'message' => sprintf('Regenerated file path for song ID %d.', $songId)];
			} catch (\Throwable $e) {
				return ['success' => false, 'message' => sprintf('Cannot fix naming rule for song ID %d: %s', $songId, $e->getMessage())];
			}
		}

		if ($issueType === IntegrityIssue::TYPE_DUPLICATE_FILE_PATH && $songId !== null) {
			['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
			$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

			try {
				$song = $this->songMapper->findSong($databasePath, $songId);
				if ($song === null) {
					return ['success' => false, 'message' => sprintf('Song ID %d not found.', $songId)];
				}

				$allSongs = $this->songMapper->findSongs($databasePath);
				$duplicateSongIds = [];
				foreach ($song->files as $f) {
					$cleanF = strtolower(ltrim($f->path, '/'));
					foreach ($allSongs as $otherSong) {
						foreach ($otherSong->files as $otherFile) {
							if ($cleanF === strtolower(ltrim($otherFile->path, '/'))) {
								$duplicateSongIds[] = $otherSong->id;
							}
						}
					}
				}
				$duplicateSongIds = array_values(array_unique($duplicateSongIds));

				if (count($duplicateSongIds) < 2) {
					return ['success' => false, 'message' => 'No duplicate songs found for this file path.'];
				}

				sort($duplicateSongIds);
				$targetSongId = $duplicateSongIds[0];
				$mergedCount = 0;

				for ($i = 1; $i < count($duplicateSongIds); $i++) {
					$droppedId = $duplicateSongIds[$i];
					$this->songMapper->mergeSongs($databasePath, $targetSongId, $droppedId);
					$mergedCount++;
				}

				$hashCodes?->save();

				return [
					'success' => true,
					'message' => sprintf('Merged %d duplicate song(s) into song ID %d.', $mergedCount, $targetSongId),
				];
			} catch (\Throwable $e) {
				return ['success' => false, 'message' => sprintf('Cannot resolve duplicate path for song ID %d: %s', $songId, $e->getMessage())];
			}
		}

		return ['success' => false, 'message' => 'Unrecognized or unfixable issue type.'];
	}

	/**
	 * @param list<\OCA\MobileSheetsManager\Db\MobileSheetsSong> $songs
	 */
	private function areSongsExactDuplicates(array $songs): bool {
		if (count($songs) <= 1) {
			return true;
		}

		$first = $songs[0];
		$extractNames = static function (array $entities): array {
			$names = array_map(static fn($e) => $e->name ?? $e->title ?? (string)$e->id, $entities);
			sort($names, SORT_STRING);
			return $names;
		};

		$firstComposers = $extractNames($first->composers);
		$firstArtists = $extractNames($first->artists);
		$firstBooks = $extractNames($first->books);
		$firstCollections = $extractNames($first->collections);
		$firstGenres = $extractNames($first->genres);
		$firstKeys = $extractNames($first->keys);

		for ($i = 1; $i < count($songs); $i++) {
			$s = $songs[$i];
			if (trim(strtolower($s->title)) !== trim(strtolower($first->title))) {
				return false;
			}
			if ($extractNames($s->composers) !== $firstComposers) {
				return false;
			}
			if ($extractNames($s->artists) !== $firstArtists) {
				return false;
			}
			if ($extractNames($s->books) !== $firstBooks) {
				return false;
			}
			if ($extractNames($s->collections) !== $firstCollections) {
				return false;
			}
			if ($extractNames($s->genres) !== $firstGenres) {
				return false;
			}
			if ($extractNames($s->keys) !== $firstKeys) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Recursively check targetFolder for files not present in DB.
	 *
	 * @param array<string, mixed> $referencedRelativePaths
	 * @param list<IntegrityIssue> $issues
	 */
	private function scanOrphans(Folder $folder, string $targetFolderPath, array $referencedRelativePaths, array &$issues): void {
		$nodes = $folder->getDirectoryListing();
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				$this->scanOrphans($node, $targetFolderPath, $referencedRelativePaths, $issues);
			} elseif ($node instanceof Node) {
				$nodePath = $node->getPath();
				$relativeToTarget = str_starts_with($nodePath, $targetFolderPath . '/')
					? substr($nodePath, strlen($targetFolderPath) + 1)
					: $node->getName();
				$filename = $node->getName();

				// Ignore database file and hash codes file
				if ($filename === 'mobilesheets.db' || str_ends_with($filename, '.db') || str_ends_with($filename, 'hashcodes.txt')) {
					continue;
				}

				if (!isset($referencedRelativePaths[$relativeToTarget])) {
					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_ORPHAN_FILE,
						severity: IntegrityIssue::SEVERITY_WARNING,
						message: sprintf('File "%s" exists in library storage but is not linked to any song.', $filename),
						path: $relativeToTarget,
						fixable: false,
					);
				}
			}
		}
	}
}
