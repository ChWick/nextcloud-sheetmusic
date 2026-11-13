<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\DuplicateSongEntityAssignment;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Db\OrphanSongEntityAssignment;
use OCA\SheetMusic\Db\OrphanSongFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfMetadata;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class LibraryIntegrityService {
	private LibraryOrphanScanner $orphanScanner;
	private LibraryIntegrityFixer $integrityFixer;

	public function __construct(
		private LibraryService $libraryService,
		private FileManager $fileManager,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
		private InternalEntityMapper $internalEntityMapper,
		private InternalSongMapper $internalSongMapper,
		private InternalSongFileMapper $internalSongFileMapper,
		private LibraryMapper $libraryMapper,
		private InternalSongAttributeMapper $internalSongAttributeMapper,
		private InternalMiscMapper $internalMiscMapper,
		?LibraryOrphanScanner $orphanScanner = null,
		?LibraryIntegrityFixer $integrityFixer = null,
	) {
		$this->orphanScanner = $orphanScanner ?? new LibraryOrphanScanner(
			$this->fileManager,
			$this->rootFolder,
			$this->pathResolver,
		);
		$this->integrityFixer = $integrityFixer ?? new LibraryIntegrityFixer(
			$this->libraryService,
			$this->fileManager,
			$this->rootFolder,
			$this->pathResolver,
			$this->logger,
			$this->internalEntityMapper,
			$this->internalSongMapper,
			$this->internalSongFileMapper,
			$this->libraryMapper,
			$this->internalSongAttributeMapper,
			$this->internalMiscMapper,
			$this->orphanScanner,
			$this,
		);
		$this->integrityFixer->setIntegrityService($this);
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
	 * @param callable(string, int, int, string|null): void|null $onProgress
	 * @return list<IntegrityIssue>
	 */
	public function checkIntegrity(int $libraryId, string $userId, bool $autoMatchOrphans = true, ?callable $onProgress = null): array {
		if ($onProgress !== null) {
			$onProgress('entities', 0, 1, 'Checking database entities...');
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();
		$targetFolder = $this->fileManager->libraryFolder($libraryId, $user);

		$songs = $this->internalSongMapper->findLibraryData($libraryId, $user)->songs;
		$targetFolderPath = (string)$targetFolder->getPath();

		$issues = [];
		$referencedRelativePaths = []; // relativeToTarget => list<array{songId: int, songTitle: string, fileId: int}>
		$songsById = [];
		$missingSongIds = [];
		foreach ($songs as $song) {
			$songsById[$song->id] = $song;
		}

		$issues = [
			...$this->checkDuplicateEntityAssignments($libraryId, $songsById),
			...$this->checkDuplicateEntities($libraryId),
		];

		$autoPaths = $this->libraryService->isAutoPathsEnabled($userId, $libraryId);
		$autoPdfMetadata = $this->libraryService->isAutoPdfMetadataEnabled($userId, $libraryId);
		$preset = $this->libraryService->getNamingRulePreset($userId, $libraryId);
		$namingRuleIssuesBySong = [];
		$reportedMissingPaths = [];

		$totalSongs = count($songs);
		$songIndex = 0;
		foreach ($songs as $song) {
			$songIndex++;
			if ($onProgress !== null) {
				$onProgress('audit', $songIndex, $totalSongs, $song->title);
			}
			foreach ($song->files as $file) {
				$pathObj = $this->pathResolver->fromStored($file->path, $user);
				if ($pathObj === null) {
					$missingSongIds[$song->id] = true;
					$reportedMissingPaths[$file->path] = true;
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
				$relativeToTarget = $this->pathResolver->relativeToFolder($pathObj->internal, $targetFolderPath);

				$sourceExists = $this->fileNodeExists($targetFolder, $relativeToTarget);
				$existingPdfPath = $sourceExists ? $relativeToTarget : null;

				// Calculate valid target paths (naming rules)
				$validTargetPaths = [];
				$hasNamingRule = true;
				if ($autoPaths) {
					try {
						$validTargetPaths = createTargetPaths(
							$song,
							getFileExtension($file->path),
							fileIndexOf($song, $file),
							fileCountForExtension($song, $file),
							$preset,
						);
					} catch (NoNamingRuleException) {
						// Song missing composer, artist, or collection - keeps existing name
						$hasNamingRule = false;
					}
				}

				if ($sourceExists) {
					$cleanRel = strtolower(ltrim($relativeToTarget, '/'));
					$referencedRelativePaths[$cleanRel][] = [
						'songId' => $song->id,
						'songTitle' => $song->title,
						'fileId' => $file->id,
					];

					if ($autoPdfMetadata && $existingPdfPath !== null && str_ends_with(strtolower($file->path), '.pdf')) {
						$pdfIssue = $this->checkPdfMetadata($targetFolder, $existingPdfPath, $song, $file->id);
						if ($pdfIssue !== null) {
							$issues[] = $pdfIssue;
						}
					}

					$trimmedOrder = trim((string)($file->pageOrder ?? ''));
					$physicalPageCount = null;
					if ($existingPdfPath !== null && str_ends_with(strtolower($file->path), '.pdf')) {
						try {
							if ($targetFolder->nodeExists($existingPdfPath)) {
								$pdfNode = $targetFolder->get($existingPdfPath);
								if ($pdfNode instanceof File) {
									$pdfContent = $pdfNode->getContent();
									if (is_string($pdfContent) && $pdfContent !== '') {
										$detected = \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf::pageCount($pdfContent);
										if ($detected > 0) {
											$physicalPageCount = $detected;
										}
									}
								}
							}
						} catch (\Throwable) {
						}
					}
					if ($physicalPageCount === null && $file->fileId > 0 && str_ends_with(strtolower($file->path), '.pdf')) {
						try {
							$nodes = $userFolder->getById($file->fileId);
							if (!empty($nodes) && $nodes[0] instanceof File) {
								$pdfContent = $nodes[0]->getContent();
								if (is_string($pdfContent) && $pdfContent !== '') {
									$detected = \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf::pageCount($pdfContent);
									if ($detected > 0) {
										$physicalPageCount = $detected;
									}
								}
							}
						} catch (\Throwable) {
						}
					}

					// Check for physical vs stored page count mismatch
					$recordedPageCount = max(1, (int)($file->pageCount ?? 0));
					if ($physicalPageCount !== null && $physicalPageCount !== $recordedPageCount) {
						$issues[] = new IntegrityIssue(
							type: IntegrityIssue::TYPE_PAGE_COUNT_MISMATCH,
							severity: IntegrityIssue::SEVERITY_WARNING,
							message: sprintf('File "%s" has %d physical pages, but database records %d pages.', basename($file->path), $physicalPageCount, $recordedPageCount),
							songId: $song->id,
							songTitle: $song->title,
							fileId: $file->id,
							path: $relativeToTarget,
							suggestedPath: null,
							fixable: true,
							details: [
								'pageCount' => $physicalPageCount,
								'recordedPageCount' => $recordedPageCount,
							],
						);
					}

					// Check naming rule compliance
					$isCompliant = !$autoPaths || !$hasNamingRule;
					if ($autoPaths && $hasNamingRule) {
						$normalizedRelative = strtolower(ltrim($relativeToTarget, '/'));
						foreach ($validTargetPaths as $validPath) {
							if ($normalizedRelative === strtolower(ltrim($validPath, '/'))) {
								$isCompliant = true;
								break;
							}
						}
					}

					if (!$isCompliant) {
						$namingRuleIssuesBySong[$song->id][] = new IntegrityIssue(
							type: IntegrityIssue::TYPE_INVALID_NAMING_RULE,
							severity: IntegrityIssue::SEVERITY_WARNING,
							message: sprintf('File path "%s" does not match standard naming rule.', basename($file->path)),
							songId: $song->id,
							songTitle: $song->title,
							fileId: $file->id,
							path: $relativeToTarget,
							suggestedPath: $validTargetPaths[0] ?? null,
							fixable: !empty($validTargetPaths),
						);
					}
				} else {
					$missingSongIds[$song->id] = true;
					$reportedMissingPaths[$file->path] = true;
					if ($relativeToTarget !== null) {
						$reportedMissingPaths[$relativeToTarget] = true;
					}
					// Source file does not exist at stored path.
					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_MISSING_FILE,
						severity: IntegrityIssue::SEVERITY_ERROR,
						message: sprintf('File "%s" referenced by song "%s" does not exist in Nextcloud storage.', basename($file->path), $song->title),
						songId: $song->id,
						songTitle: $song->title,
						fileId: $file->id,
						path: $relativeToTarget ?? $file->path,
						fixable: false,
						details: [
							'source' => 'song_files',
							'fileId' => $file->id,
							'songId' => $song->id,
							'path' => $file->path,
						],
					);
				}
			}
		}

		// Only include invalid naming rule issues if the files themselves exist (no missing files for the song)
		foreach ($namingRuleIssuesBySong as $songId => $namingIssues) {
			if (!isset($missingSongIds[$songId])) {
				foreach ($namingIssues as $namingIssue) {
					$issues[] = $namingIssue;
				}
			}
		}

		// Check for duplicate physical file references
		$issues = [
			...$issues,
			...$this->checkDuplicateFilePaths($referencedRelativePaths, $songsById),
		];

		// Scan folder for orphan files (files in storage not in DB)
		if ($autoPaths) {
			if ($onProgress !== null) {
				$onProgress('orphans', 0, 1, 'Scanning storage for orphan files...');
			}
			$this->orphanScanner->scanOrphans($targetFolder, $targetFolderPath, $referencedRelativePaths, $issues, $user, $songs, $missingSongIds, $autoMatchOrphans);
		}

		if ($onProgress !== null) {
			$onProgress('finalizing', 0, 1, 'Checking relational integrity...');
		}

		// Check for songs with identical metadata (informational)
		$issues = [
			...$issues,
			...$this->checkIdenticalSongs($songs),
			...$this->checkRelationalIntegrity($libraryId, $user, $targetFolder, $reportedMissingPaths),
		];

		return $issues;
	}

	/**
	 * @param array<string, list<array{songId: int, songTitle: string, fileId: int}>> $referencedRelativePaths
	 * @param array<int, MobileSheetsSong> $songsById
	 * @return list<IntegrityIssue>
	 */
	private function checkDuplicateFilePaths(array $referencedRelativePaths, array $songsById): array {
		$issues = [];
		foreach ($referencedRelativePaths as $relPath => $references) {
			if (count($references) <= 1) {
				continue;
			}
			$songIds = array_values(array_unique(array_column($references, 'songId')));

			if (count($songIds) > 1) {
				$referencingSongs = array_values(array_filter(
					array_map(fn ($id) => $songsById[$id] ?? null, $songIds)
				));

				$areExactDuplicates = $this->areSongsExactDuplicates($referencingSongs);
				$songTitles = array_map(fn ($s) => sprintf('"%s" (ID %d)', $s->title, $s->id), $referencingSongs);

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

		return $issues;
	}

	/**
	 * @param list<MobileSheetsSong> $songs
	 * @return list<IntegrityIssue>
	 */
	private function checkIdenticalSongs(array $songs): array {
		$issues = [];
		$groupedByMetadata = [];
		foreach ($songs as $s) {
			$metaKey = $this->getCanonicalSongMetadataKey($s);
			$groupedByMetadata[$metaKey][] = $s;
		}

		foreach ($groupedByMetadata as $identicalSongs) {
			if (count($identicalSongs) <= 1) {
				continue;
			}
			$identicalIds = array_map(fn ($s) => $s->id, $identicalSongs);
			$songTitles = array_map(fn ($s) => sprintf('"%s" (ID %d)', $s->title, $s->id), $identicalSongs);

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

		return $issues;
	}

	/**
	 * @param array<int, MobileSheetsSong> $songsById
	 * @return list<IntegrityIssue>
	 */
	private function checkDuplicateEntityAssignments(int $libraryId, array $songsById): array {
		$issues = [];
		$duplicateAssignmentsBySong = [];
		$assignmentsList = $this->internalEntityMapper->findDuplicateSongEntityAssignments($libraryId);
		foreach ($assignmentsList as $assignment) {
			$duplicateAssignmentsBySong[$assignment->songId][] = $assignment;
		}

		foreach ($duplicateAssignmentsBySong as $songId => $assignments) {
			$song = $songsById[$songId] ?? null;
			$assignmentDescriptions = array_map(
				static fn (DuplicateSongEntityAssignment $assignment): string => sprintf(
					'%d duplicate %s assignment(s)',
					$assignment->count - 1,
					InternalEntityMapper::getLabel($assignment->entityType, true),
				),
				$assignments,
			);
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Song "%s" has repeated entity assignments: %s.',
					$song?->title ?? sprintf('ID %d', $songId),
					implode(', ', $assignmentDescriptions),
				),
				songId: (int)$songId,
				songTitle: $song?->title,
				fixable: true,
				details: ['duplicates' => array_map(static fn (DuplicateSongEntityAssignment $a) => $a->toArray(), $assignments)],
			);
		}

		return $issues;
	}

	/**
	 * @return list<IntegrityIssue>
	 */
	private function checkDuplicateEntities(int $libraryId): array {
		$issues = [];
		$duplicatesList = $this->internalEntityMapper->findDuplicateEntities($libraryId);
		foreach ($duplicatesList as $duplicateEntity) {
			$label = InternalEntityMapper::getLabel($duplicateEntity->entityType);
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_DUPLICATE_ENTITY,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Duplicate %s "%s" entries found (IDs %s). Auto-fix will keep ID %d and reassign songs.',
					$label,
					$duplicateEntity->name,
					implode(', ', array_merge([$duplicateEntity->keepId], $duplicateEntity->duplicateIds)),
					$duplicateEntity->keepId,
				),
				fixable: true,
				details: $duplicateEntity->toArray(),
			);
		}

		return $issues;
	}

	/**
	 * @param list<int> $ids
	 * @param array<string, mixed> $extraDetails
	 */
	private function createDuplicateClientIdIssue(
		string $subject,
		string $idGroupLabel,
		int $clientId,
		array $ids,
		string $target,
		?int $songId = null,
		array $extraDetails = [],
	): IntegrityIssue {
		$keepId = $ids[0];
		$duplicateIds = array_slice($ids, 1);

		return new IntegrityIssue(
			type: IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID,
			severity: IntegrityIssue::SEVERITY_WARNING,
			message: sprintf(
				'Duplicate %s client_id %d shared by %s IDs %s. Auto-fix will keep client_id on ID %d and reset duplicates.',
				$subject,
				$clientId,
				$idGroupLabel,
				implode(', ', $ids),
				$keepId,
			),
			songId: $songId,
			fixable: true,
			details: [
				'target' => $target,
				'clientId' => $clientId,
				'keepId' => $keepId,
				'duplicateIds' => $duplicateIds,
				...$extraDetails,
			],
		);
	}

	/**
	 * @return list<IntegrityIssue>
	 */
	private function checkRelationalIntegrity(
		int $libraryId,
		?IUser $user = null,
		?Folder $targetFolder = null,
		array &$reportedMissingPaths = [],
	): array {
		$issues = [];

		$orphanAssignments = $this->internalEntityMapper->findOrphanSongEntityAssignments($libraryId);
		if (!empty($orphanAssignments)) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Found %d orphan song-entity assignment(s) referencing deleted or missing records.',
					count($orphanAssignments),
				),
				fixable: true,
				details: [
					'count' => count($orphanAssignments),
					'assignments' => array_map(static fn (OrphanSongEntityAssignment $a): array => $a->toArray(), $orphanAssignments),
				],
			);
		}

		$duplicateSongClientIds = $this->internalSongMapper->findDuplicateClientIds($libraryId);
		foreach ($duplicateSongClientIds as $dup) {
			$issues[] = $this->createDuplicateClientIdIssue(
				subject: 'song',
				idGroupLabel: 'song',
				clientId: $dup->clientId,
				ids: $dup->songIds,
				target: 'song',
				songId: $dup->songIds[0],
			);
		}

		$duplicateEntityClientIds = $this->internalEntityMapper->findDuplicateClientIds($libraryId);
		foreach ($duplicateEntityClientIds as $dup) {
			$issues[] = $this->createDuplicateClientIdIssue(
				subject: InternalEntityMapper::getLabel($dup->type),
				idGroupLabel: 'entity',
				clientId: $dup->clientId,
				ids: $dup->entityIds,
				target: 'entity',
				extraDetails: ['entityType' => $dup->type],
			);
		}

		$songsWithoutFiles = $this->internalSongMapper->findSongsWithoutFiles($libraryId);
		foreach ($songsWithoutFiles as $row) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_SONG_WITHOUT_FILES,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf('Song "%s" (ID %d) has no associated files.', $row->title, $row->id),
				songId: $row->id,
				songTitle: $row->title,
				fixable: false,
			);
		}

		$orphanSongFiles = $this->internalSongFileMapper->findOrphanSongFiles($libraryId);
		if (!empty($orphanSongFiles)) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_ORPHAN_SONG_FILE,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Found %d orphan song file record(s) referencing deleted or missing songs.',
					count($orphanSongFiles),
				),
				fixable: true,
				details: [
					'count' => count($orphanSongFiles),
					'files' => array_map(static fn (OrphanSongFile $f): array => $f->toArray(), $orphanSongFiles),
				],
			);

			// Also check if any orphan song file does not exist in Nextcloud storage
			if ($user !== null) {
				try {
					$userFolder = $this->rootFolder->getUserFolder($user->getUID());
					foreach ($orphanSongFiles as $orphan) {
						$nodes = [];
						try {
							$nodes = $userFolder->getById($orphan->fileId);
						} catch (\Throwable) {
						}
						if ($nodes === []) {
							$issues[] = new IntegrityIssue(
								type: IntegrityIssue::TYPE_MISSING_FILE,
								severity: IntegrityIssue::SEVERITY_ERROR,
								message: sprintf('Song file (ID %d) has no connected song and referenced file does not exist in Nextcloud storage.', $orphan->id),
								fileId: $orphan->id,
								fixable: true,
								details: [
									'source' => 'orphan_song_file',
									'orphanSongFileId' => $orphan->id,
									'fileId' => $orphan->fileId,
								],
							);
						}
					}
				} catch (\Throwable) {
				}
			}
		}

		$orphanAttrs = $this->internalSongAttributeMapper->findOrphanSongAttributes($libraryId);
		if (!empty($orphanAttrs)) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Found %d orphan song attribute record(s) referencing deleted songs.',
					count($orphanAttrs),
				),
				fixable: true,
				details: [
					'count' => count($orphanAttrs),
					'attributes' => $orphanAttrs,
				],
			);
		}

		$orphanMisc = $this->internalMiscMapper->findOrphanMiscData($libraryId);
		if (!empty($orphanMisc)) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_ORPHAN_MISC_DATA,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Found %d orphan sync/misc data record(s) referencing deleted or missing songs.',
					count($orphanMisc),
				),
				fixable: true,
				details: [
					'count' => count($orphanMisc),
					'misc' => $orphanMisc,
				],
			);
		}

		return $issues;
	}

	private function checkPdfMetadata(
		Folder $targetFolder,
		string $relativePath,
		MobileSheetsSong $song,
		int $fileId,
	): ?IntegrityIssue {
		try {
			$node = $targetFolder->get($relativePath);
			if (!$node instanceof File) {
				throw new \RuntimeException('The attached PDF path does not point to a file.');
			}

			$content = $node->getContent();
			if (!is_string($content) || $content === '') {
				throw new \RuntimeException('The attached PDF is empty or unreadable.');
			}

			$mismatchedFields = MobileSheetsPdfMetadata::mismatchedFields($content, $song);
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not verify PDF metadata during library integrity audit', [
				'exception' => $exception,
				'fileId' => $fileId,
				'path' => $relativePath,
				'songId' => $song->id,
			]);

			return new IntegrityIssue(
				type: IntegrityIssue::TYPE_PDF_METADATA_UNREADABLE,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf('Could not verify PDF metadata for "%s".', basename($relativePath)),
				songId: $song->id,
				songTitle: $song->title,
				fileId: $fileId,
				path: $relativePath,
			);
		}

		if ($mismatchedFields === []) {
			return null;
		}

		return new IntegrityIssue(
			type: IntegrityIssue::TYPE_PDF_METADATA_MISMATCH,
			severity: IntegrityIssue::SEVERITY_WARNING,
			message: sprintf(
				'PDF metadata fields do not match the expected song values: %s.',
				implode(', ', $mismatchedFields),
			),
			songId: $song->id,
			songTitle: $song->title,
			fileId: $fileId,
			path: $relativePath,
			fixable: true,
			details: ['mismatchedFields' => $mismatchedFields],
		);
	}

	/**
	 * Computes a canonical metadata string for a song to detect identical metadata duplicates.
	 */
	private function getCanonicalSongMetadataKey(MobileSheetsSong $song): string {
		$extractNames = static function (array $entities): array {
			$names = array_map(static fn ($e) => $e->name ?? $e->title ?? (string)($e->id ?? ''), $entities);
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
	 * @param list<MobileSheetsSong> $songs
	 */
	private function areSongsExactDuplicates(array $songs): bool {
		if (count($songs) <= 1) {
			return true;
		}

		$firstKey = $this->getCanonicalSongMetadataKey($songs[0]);
		for ($i = 1; $i < count($songs); $i++) {
			if ($this->getCanonicalSongMetadataKey($songs[$i]) !== $firstKey) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Automatically repair an integrity issue.
	 *
	 * @return array{success: bool, fixedCount: int, message: string}
	 */
	public function fixIssue(
		int $libraryId,
		string $userId,
		string $issueType,
		?int $songId = null,
		?int $fileId = null,
		?string $entityType = null,
		?int $entityId = null,
		?string $path = null,
	): array {
		return $this->integrityFixer->fixIssue(
			$libraryId,
			$userId,
			$issueType,
			$songId,
			$fileId,
			$entityType,
			$entityId,
			$path,
		);
	}

	/**
	 * Automatically resolve/fix all fixable issues of a specific type (or all fixable types).
	 *
	 * @return array{success: bool, fixedCount: int, message: string}
	 */
	public function fixAllIssues(int $libraryId, string $userId, ?string $issueType = null, ?callable $onProgress = null): array {
		return $this->integrityFixer->fixAllIssues($libraryId, $userId, $issueType, $onProgress);
	}

	/**
	 * Auto-fix an orphan file by attaching it to the single matched song.
	 *
	 * @return array{success: bool, fixedCount: int, message: string}
	 */
	public function fixOrphanFileIssue(int $libraryId, string $userId, string $path, ?int $songId = null): array {
		return $this->integrityFixer->fixOrphanFileIssue($libraryId, $userId, $path, $songId);
	}

	/**
	 * Check whether a file referenced in MobileSheets actually exists in library storage.
	 */
	public function fileNodeExists(Folder $targetFolder, ?string $relativeToTarget): bool {
		return $this->orphanScanner->fileNodeExists($targetFolder, $relativeToTarget);
	}

	/**
	 * Find IDs of songs that have at least one missing file.
	 *
	 * @param list<MobileSheetsSong> $songs
	 * @return array<int, bool> map of songId => true
	 */
	public function findMissingSongIds(
		array $songs,
		Folder $targetFolder,
		string $targetFolderPath,
		\OCP\IUser $user,
	): array {
		return $this->orphanScanner->findMissingSongIds($songs, $targetFolder, $targetFolderPath, $user);
	}

	/**
	 * Find candidate song for an orphan file.
	 *
	 * @param list<MobileSheetsSong> $songs
	 * @param array<int, bool>|list<int> $missingSongIds
	 */
	public function findOrphanFileCandidateSong(string $filePath, array $songs, array $missingSongIds = []): ?MobileSheetsSong {
		return $this->orphanScanner->findOrphanFileCandidateSong($filePath, $songs, $missingSongIds);
	}
}
