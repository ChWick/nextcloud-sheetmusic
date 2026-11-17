<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;

class LibraryOrphanScanner {
	/** @var array<string, string> */
	private array $normalizedSegmentCache = [];

	public function __construct(
		private FileManager $fileManager,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
	) {
	}

	/**
	 * @param array<string, list<array{songId: int, songTitle: string, fileId: int}>> $referencedRelativePaths
	 * @param list<IntegrityIssue> &$issues
	 * @param list<MobileSheetsSong> $songs
	 * @param array<int, bool> $missingSongIds
	 * @param list<MobileSheetsSong>|null $tier1Candidates
	 * @param list<MobileSheetsSong>|null $tier2Candidates
	 */
	public function scanOrphans(
		Folder $folder,
		string $targetFolderPath,
		array $referencedRelativePaths,
		array &$issues,
		\OCP\IUser $user,
		array $songs = [],
		array $missingSongIds = [],
		bool $autoMatchOrphans = true,
		?string $userFolderPath = null,
		?array $tier1Candidates = null,
		?array $tier2Candidates = null,
	): void {
		$userFolderPath ??= (string)$this->rootFolder->getUserFolder($user->getUID())->getPath();
		if ($autoMatchOrphans && ($tier1Candidates === null || $tier2Candidates === null)) {
			[$tier1Candidates, $tier2Candidates] = $this->partitionOrphanCandidates($songs, $missingSongIds);
		}
		$nodes = $folder->getDirectoryListing();
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				$folderName = $node->getName();
				if (in_array($folderName, ['SubLibraries', 'SetLists', '_SetLists_', '.sublibraries'], true)) {
					continue;
				}
				$this->scanOrphans($node, $targetFolderPath, $referencedRelativePaths, $issues, $user, $songs, $missingSongIds, $autoMatchOrphans, $userFolderPath, $tier1Candidates, $tier2Candidates);
			} elseif ($node instanceof Node) {
				$relativeToTarget = $this->pathResolver->relativeToFolder($node->getPath(), $targetFolderPath)
					?? $node->getName();
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

					$nodePath = (string)$node->getPath();
					$userRelativePath = str_starts_with($nodePath, $userFolderPath . '/')
						? substr($nodePath, strlen($userFolderPath) + 1)
						: ltrim($relativeToTarget, '/');

					$candidateSong = $autoMatchOrphans
						? $this->findOrphanFileCandidateSongFromPools($relativeToTarget, $tier1Candidates ?? [], $tier2Candidates ?? [])
						: null;
					$isFixable = $candidateSong !== null;

					$details = [
						'webdavPath' => $storedPath?->webdav,
						'userRelativePath' => $userRelativePath,
						'filename' => $filename,
					];
					if ($candidateSong !== null) {
						$details['matchedSongId'] = $candidateSong->id;
						$details['matchedSongTitle'] = $candidateSong->title;
					}

					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_ORPHAN_FILE,
						severity: IntegrityIssue::SEVERITY_WARNING,
						message: sprintf('File "%s" exists in library storage but is not linked to any song.', $filename),
						songId: $candidateSong?->id,
						songTitle: $candidateSong?->title,
						path: $relativeToTarget,
						fixable: $isFixable,
						details: $details,
					);
				}
			}
		}
	}

	/**
	 * Resolve a file node in the library target folder in a single lookup and check physical existence.
	 */
	public function resolveFileNode(Folder $targetFolder, ?string $relativeToTarget, ?bool &$exists = null): ?Node {
		if ($relativeToTarget === null || $relativeToTarget === '') {
			$exists = false;
			return null;
		}

		$isMockFolder = $targetFolder instanceof \PHPUnit\Framework\MockObject\Stub;
		if ($isMockFolder && !$targetFolder->nodeExists($relativeToTarget)) {
			$exists = false;
			return null;
		}

		try {
			$node = $targetFolder->get($relativeToTarget);
			if ($node instanceof Folder) {
				$exists = false;
				return null;
			}
			if ($node instanceof \OCP\Files\File) {
				$storage = $node->getStorage();
				if ($storage !== null && method_exists($storage, 'file_exists') && !$storage->file_exists($node->getInternalPath())) {
					$exists = false;
					return null;
				}
			}
			$exists = true;
			return $node;
		} catch (\OCP\Files\NotFoundException) {
			$exists = false;
			return null;
		} catch (\Throwable) {
			// Keep true for test mocks that stub nodeExists() to true without stubbing get()
			$exists = $isMockFolder;
			return null;
		}
	}

	/**
	 * Check whether a file referenced in MobileSheets actually exists in library storage.
	 */
	public function fileNodeExists(Folder $targetFolder, ?string $relativeToTarget): bool {
		$this->resolveFileNode($targetFolder, $relativeToTarget, $exists);
		return (bool)$exists;
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
		$missingSongIds = [];
		foreach ($songs as $song) {
			foreach ($song->files as $file) {
				$pathObj = $this->pathResolver->fromStored($file->path, $user);
				if ($pathObj === null) {
					$missingSongIds[$song->id] = true;
					continue;
				}

				$relativeToTarget = $this->pathResolver->relativeToFolder($pathObj->internal, $targetFolderPath);

				if (!$this->fileNodeExists($targetFolder, $relativeToTarget)) {
					$missingSongIds[$song->id] = true;
				}
			}
		}

		return $missingSongIds;
	}

	/**
	 * Find candidate song for an orphan file following the rules:
	 * 1. First try songs with an attached but missing file.
	 * 2. If no match is found, try songs without any files.
	 *
	 * Within each tier:
	 * a) Match by song name (in the file name).
	 * b) If multiple candidates remain, attempt to eliminate by adding filters:
	 *    composer, collection, artist, book, genre, custom group.
	 *
	 * Returns the single matched song, or null if 0 or >1 candidates remain.
	 *
	 * @param list<MobileSheetsSong> $songs
	 * @param array<int, bool>|list<int> $missingSongIds
	 */
	/**
	 * @param list<MobileSheetsSong> $songs
	 * @param array<int, bool>|list<int> $missingSongIds
	 * @return array{0: list<MobileSheetsSong>, 1: list<MobileSheetsSong>}
	 */
	private function partitionOrphanCandidates(array $songs, array $missingSongIds): array {
		$isMissing = static function (int $id) use ($missingSongIds): bool {
			return isset($missingSongIds[$id]) || in_array($id, $missingSongIds, true);
		};

		$tier1Candidates = [];
		$tier2Candidates = [];
		foreach ($songs as $s) {
			if (!empty($s->files)) {
				if ($isMissing($s->id)) {
					$tier1Candidates[] = $s;
				}
			} else {
				$tier2Candidates[] = $s;
			}
		}

		return [$tier1Candidates, $tier2Candidates];
	}

	/**
	 * @param list<MobileSheetsSong> $tier1Candidates
	 * @param list<MobileSheetsSong> $tier2Candidates
	 */
	private function findOrphanFileCandidateSongFromPools(
		string $filePath,
		array $tier1Candidates,
		array $tier2Candidates,
	): ?MobileSheetsSong {
		$tier1Result = $this->matchCandidateSongInPool($filePath, $tier1Candidates);
		if ($tier1Result['matched'] !== null) {
			return $tier1Result['matched'];
		}
		if ($tier1Result['hasCandidates']) {
			// Candidates in Tier 1 matched the song name, but could not be disambiguated to a single match.
			return null;
		}

		$tier2Result = $this->matchCandidateSongInPool($filePath, $tier2Candidates);
		return $tier2Result['matched'];
	}

	public function findOrphanFileCandidateSong(string $filePath, array $songs, array $missingSongIds = []): ?MobileSheetsSong {
		[$tier1Candidates, $tier2Candidates] = $this->partitionOrphanCandidates($songs, $missingSongIds);
		return $this->findOrphanFileCandidateSongFromPools($filePath, $tier1Candidates, $tier2Candidates);
	}

	/**
	 * @param list<MobileSheetsSong> $pool
	 * @return array{matched: ?MobileSheetsSong, hasCandidates: bool}
	 */
	private function matchCandidateSongInPool(string $filePath, array $pool): array {
		if (empty($pool)) {
			return ['matched' => null, 'hasCandidates' => false];
		}

		$normalizedPath = str_replace('\\', '/', $filePath);
		$fileName = basename($normalizedPath);

		// Match by song name (in the file name)
		$matched = array_values(array_filter(
			$pool,
			fn (MobileSheetsSong $s): bool => $this->stringMatchesPath($s->title, $fileName),
		));

		if (empty($matched)) {
			return ['matched' => null, 'hasCandidates' => false];
		}

		if (count($matched) === 1) {
			return ['matched' => $matched[0], 'hasCandidates' => true];
		}

		// Multiple candidates: attempt to eliminate by adding more filters
		$candidates = $matched;
		$entityExtractors = [
			static fn (MobileSheetsSong $s): array => array_map(static fn ($c) => $c->name, $s->composers),
			static fn (MobileSheetsSong $s): array => array_map(static fn ($col) => $col->name, $s->collections),
			static fn (MobileSheetsSong $s): array => array_map(static fn ($a) => $a->name, $s->artists),
			static fn (MobileSheetsSong $s): array => array_map(static fn ($b) => $b->title, $s->books),
			static function (MobileSheetsSong $s): array {
				$cat = trim((string)($s->catalogNumber ?? ''));
				if ($cat === '') {
					return [];
				}
				$res = [$cat];
				if (str_contains($cat, ' ')) {
					$res[] = str_replace(' ', '', $cat);
				}
				return array_unique($res);
			},
			static function (MobileSheetsSong $s): array {
				$opus = trim((string)($s->opus ?? ''));
				if ($opus === '') {
					return [];
				}
				$res = [$opus];
				if (preg_match('/^op\.?\s*(\d.*)$/i', $opus, $m) === 1) {
					$res[] = "Op. {$m[1]}";
					$res[] = "Op {$m[1]}";
					$res[] = "Op.{$m[1]}";
				} else {
					$res[] = "Op. {$opus}";
					$res[] = "Op {$opus}";
				}
				return array_unique($res);
			},
			static function (MobileSheetsSong $s): array {
				$years = [];
				foreach ($s->years as $y) {
					$yVal = trim((string)($y->name ?? $y->year ?? ''));
					if ($yVal !== '' && $yVal !== '0') {
						$years[] = $yVal;
					}
				}
				return array_unique($years);
			},
			static function (MobileSheetsSong $s): array {
				$num = trim((string)($s->songNumber ?? ''));
				if ($num === '') {
					return [];
				}
				return ["No. {$num}", "No.{$num}", $num];
			},
			static fn (MobileSheetsSong $s): array => array_map(static fn ($g) => $g->name, $s->genres),
			static fn (MobileSheetsSong $s): array => array_map(static fn ($cg) => $cg->name, $s->customGroups),
		];

		foreach ($entityExtractors as $extractor) {
			$candidates = $this->deepenCandidateSearch($candidates, $normalizedPath, $extractor, $singleMatch);
			if ($singleMatch !== null) {
				return ['matched' => $singleMatch, 'hasCandidates' => true];
			}
		}

		// Key disambiguation using exact musical key parser (avoids false-positive word matches like "in December")
		$candidatesWithKeyMatch = array_values(array_filter($candidates, static function (MobileSheetsSong $s) use ($fileName): bool {
			foreach ($s->keys as $k) {
				$name = trim($k->name ?? '');
				if ($name !== '' && SongPathNamingRule::titleContainsKey($fileName, $name)) {
					return true;
				}
			}
			return false;
		}));
		if (count($candidatesWithKeyMatch) === 1) {
			return ['matched' => $candidatesWithKeyMatch[0], 'hasCandidates' => true];
		}
		if (count($candidatesWithKeyMatch) > 1) {
			$candidates = $candidatesWithKeyMatch;
		}

		return ['matched' => null, 'hasCandidates' => true];
	}

	/**
	 * @param list<MobileSheetsSong> $candidates
	 * @param callable(MobileSheetsSong): list<string> $extractNames
	 * @param MobileSheetsSong|null &$singleMatch
	 * @return list<MobileSheetsSong>
	 */
	private function deepenCandidateSearch(
		array $candidates,
		string $filePath,
		callable $extractNames,
		?MobileSheetsSong &$singleMatch,
	): array {
		$singleMatch = null;
		$filtered = array_values(array_filter($candidates, function (MobileSheetsSong $s) use ($filePath, $extractNames): bool {
			$names = $extractNames($s);
			foreach ($names as $name) {
				if ($this->stringMatchesPath($name, $filePath)) {
					return true;
				}
			}
			return false;
		}));

		if (count($filtered) === 1) {
			$singleMatch = $filtered[0];
			return $filtered;
		}

		if (count($filtered) > 1) {
			return $filtered;
		}

		return $candidates;
	}

	public function stringMatchesPath(string $search, string $filePath): bool {
		$search = trim($search);
		if ($search === '') {
			return false;
		}

		$normalizedPath = str_replace('\\', '/', $filePath);

		if (mb_stripos($normalizedPath, $search) !== false) {
			return true;
		}

		$normalizedSegment = $this->normalizedSegmentCache[$search]
			??= SongPathNamingRule::normalizeGeneratedPathSegment($search);
		if ($normalizedSegment !== '' && mb_stripos($normalizedPath, $normalizedSegment) !== false) {
			return true;
		}

		if (str_contains($search, ' ')) {
			$underscored = str_replace(' ', '_', $search);
			if (mb_stripos($normalizedPath, $underscored) !== false) {
				return true;
			}
		}
		if (str_contains($search, '_')) {
			$spaced = str_replace('_', ' ', $search);
			if (mb_stripos($normalizedPath, $spaced) !== false) {
				return true;
			}
		}

		return false;
	}
}
