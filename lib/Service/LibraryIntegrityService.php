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
								$cleanKey = strtolower(ltrim($cleanValid, '/'));
								$referencedRelativePaths[$cleanKey][] = [
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
					$cleanRel = strtolower(ltrim($relativeToTarget, '/'));
					$referencedRelativePaths[$cleanRel][] = [
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
		$this->scanOrphans($targetFolder, $targetFolderPath, $referencedRelativePaths, $issues, $user);

		// Check for songs with identical metadata (informational)
		$groupedByMetadata = [];
		foreach ($songs as $s) {
			$metaKey = $this->getCanonicalSongMetadataKey($s);
			$groupedByMetadata[$metaKey][] = $s;
		}

		foreach ($groupedByMetadata as $identicalSongs) {
			if (count($identicalSongs) > 1) {
				$identicalIds = array_map(fn($s) => $s->id, $identicalSongs);
				$songTitles = array_map(fn($s) => sprintf('"%s" (ID %d)', $s->title, $s->id), $identicalSongs);

				$issues[] = new IntegrityIssue(
					type: IntegrityIssue::TYPE_IDENTICAL_SONG,
					severity: IntegrityIssue::SEVERITY_INFO,
					message: sprintf(
						'Found %d songs with identical metadata (%s). Propose to merge or keep separate.',
						count($identicalSongs),
						implode(', ', $songTitles)
					),
					songId: $identicalSongs[0]->id,
					songTitle: $identicalSongs[0]->title,
					fixable: true,
					details: [
						'songIds' => $identicalIds,
						'exactDuplicate' => true,
					],
				);
			}
		}

		return $issues;
	}

	/**
	 * Automatically resolve/fix all fixable issues of a specific type (or all fixable types).
	 *
	 * @return array{success: bool, fixedCount: int, message: string}
	 */
	public function fixAllIssues(int $libraryId, string $userId, ?string $issueType = null): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();
		['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);

		$issues = $this->checkIntegrity($libraryId, $userId);
		$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

		$fixedCount = 0;
		$processedSongs = [];

		foreach ($issues as $issue) {
			if (!$issue->fixable) {
				continue;
			}
			if ($issueType !== null && $issueType !== '' && $issue->type !== $issueType) {
				continue;
			}

			if ($issue->type === IntegrityIssue::TYPE_INVALID_NAMING_RULE && $issue->songId !== null) {
				if (isset($processedSongs[$issue->songId])) {
					continue;
				}
				try {
					$this->fileManager->regenerateSongFilePaths($issue->songId, $user, $targetFolder, $databasePath, $hashCodes);
					$processedSongs[$issue->songId] = true;
					$fixedCount++;
				} catch (\Throwable $e) {
					$this->logger->warning(sprintf('Cannot auto-fix naming rule for song ID %d during batch fix: %s', $issue->songId, $e->getMessage()));
				}
			} elseif (($issue->type === IntegrityIssue::TYPE_DUPLICATE_FILE_PATH || $issue->type === IntegrityIssue::TYPE_IDENTICAL_SONG) && $issue->songId !== null) {
				if (isset($processedSongs[$issue->songId])) {
					continue;
				}
				try {
					$song = $this->songMapper->findSong($databasePath, $issue->songId);
					if ($song === null) {
						continue;
					}

					$allSongs = $this->songMapper->findSongs($databasePath);
					$duplicateSongIds = [];

					if ($issue->type === IntegrityIssue::TYPE_DUPLICATE_FILE_PATH) {
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
					} else {
						$targetMetaKey = $this->getCanonicalSongMetadataKey($song);
						foreach ($allSongs as $otherSong) {
							if ($this->getCanonicalSongMetadataKey($otherSong) === $targetMetaKey) {
								$duplicateSongIds[] = $otherSong->id;
							}
						}
					}

					$duplicateSongIds = array_values(array_unique($duplicateSongIds));
					if (count($duplicateSongIds) >= 2) {
						sort($duplicateSongIds);
						$targetSongId = $duplicateSongIds[0];

						for ($i = 1; $i < count($duplicateSongIds); $i++) {
							$droppedId = $duplicateSongIds[$i];
							$this->songMapper->mergeSongs($databasePath, $targetSongId, $droppedId);
							$processedSongs[$droppedId] = true;
						}
						$processedSongs[$targetSongId] = true;
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning(sprintf('Cannot auto-fix duplicate/identical songs for song ID %d during batch fix: %s', $issue->songId, $e->getMessage()));
				}
			}
		}

		if ($fixedCount > 0) {
			$hashCodes?->save();
		}

		return [
			'success' => true,
			'fixedCount' => $fixedCount,
			'message' => sprintf('Applied autofix to %d issue(s).', $fixedCount),
		];
	}

	/**
	 * Computes a canonical metadata string for a song to detect identical metadata duplicates.
	 */
	private function getCanonicalSongMetadataKey(\OCA\MobileSheetsManager\Db\MobileSheetsSong $song): string {
		$extractNames = static function (array $entities): array {
			$names = array_map(static fn($e) => $e->name ?? $e->title ?? (string)($e->id ?? ''), $entities);
			sort($names, SORT_STRING);
			return $names;
		};

		$parts = [
			'title:' => trim(strtolower($song->title)),
			'composers:' => implode('|', $extractNames($song->composers)),
			'artists:' => implode('|', $extractNames($song->artists)),
			'books:' => implode('|', $extractNames($song->books)),
			'collections:' => implode('|', $extractNames($song->collections)),
			'genres:' => implode('|', $extractNames($song->genres)),
			'keys:' => implode('|', $extractNames($song->keys)),
			'customGroups:' => implode('|', $extractNames($song->customGroups)),
			'years:' => implode('|', $extractNames($song->years)),
			'signatures:' => implode('|', $extractNames($song->signatures)),
			'sourceTypes:' => implode('|', $extractNames($song->sourceTypes)),
		];

		return json_encode($parts);
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
	private function scanOrphans(Folder $folder, string $targetFolderPath, array $referencedRelativePaths, array &$issues, \OCP\IUser $user): void {
		$nodes = $folder->getDirectoryListing();
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				$this->scanOrphans($node, $targetFolderPath, $referencedRelativePaths, $issues, $user);
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

				$cleanRelative = strtolower(ltrim($relativeToTarget, '/'));
				if (!isset($referencedRelativePaths[$cleanRelative])) {
					$storedPath = null;
					try {
						$storedPath = $this->fileManager->pathOfExistingFile($node->getPath(), $user);
					} catch (\Throwable) {
						// ignore if path cannot be resolved
					}

					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_ORPHAN_FILE,
						severity: IntegrityIssue::SEVERITY_WARNING,
						message: sprintf('File "%s" exists in library storage but is not linked to any song.', $filename),
						path: $relativeToTarget,
						fixable: false,
						details: [
							'webdavPath' => $storedPath?->webdav,
							'userRelativePath' => $storedPath?->userRelative,
							'filename' => $filename,
						],
					);
				}
			}
		}
	}
}
