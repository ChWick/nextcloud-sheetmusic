<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;

class SongPathNamingRule {
	public const PRESET_AUTO = 'auto';
	public const PRESET_COMPOSER = 'composer';
	public const PRESET_ARTIST = 'artist';
	public const PRESET_COLLECTION = 'collection';
	public const PRESET_DISABLED = 'disabled';

	public const PRESETS = [
		self::PRESET_AUTO,
		self::PRESET_COMPOSER,
		self::PRESET_ARTIST,
		self::PRESET_COLLECTION,
		self::PRESET_DISABLED,
	];

	public static function getFileExtension(string $filename): string {
		$extension = pathinfo($filename, PATHINFO_EXTENSION);

		return $extension !== '' ? '.' . $extension : '';
	}

	/**
	 * Zero-padded index prefix used to tell the files of a multi-file song apart,
	 * e.g. "001 " producing "001 Miles - Real Book - Blue.pdf".
	 *
	 * Files with different extensions (e.g. .pdf and .mid) do not compete for the
	 * same file name and keep their plain, unprefixed name.
	 *
	 * A song with a single file (or only one file for given extension) keeps its plain, unprefixed name.
	 */
	public static function fileIndexPrefix(?int $fileIndex, int $fileCount): string {
		if ($fileIndex === null || $fileCount < 2) {
			return '';
		}

		return sprintf('%03d ', $fileIndex);
	}

	/**
	 * 1-based position of $file within $song among files sharing the same extension,
	 * or null when it is not listed there.
	 */
	public static function fileIndexOf(MobileSheetsSong $song, MobileSheetsFile $file): ?int {
		$ext = strtolower(self::getFileExtension($file->path));
		$position = 0;
		foreach ($song->files as $candidate) {
			if (strtolower(self::getFileExtension($candidate->path)) === $ext) {
				$position++;
				if ($candidate->id === $file->id) {
					return $position;
				}
			}
		}

		return null;
	}

	/** Number of files in $song sharing the same extension as $file. */
	public static function fileCountForExtension(MobileSheetsSong $song, MobileSheetsFile $file): int {
		$ext = strtolower(self::getFileExtension($file->path));
		$count = 0;
		foreach ($song->files as $candidate) {
			if (strtolower(self::getFileExtension($candidate->path)) === $ext) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Normalize a generated path for Windows, Linux, and Nextcloud's 250-byte
	 * filename limit before it is used for a folder or file operation.
	 */
	public static function normalizeGeneratedPath(string $path): string {
		$path = str_replace('\\', '/', $path);
		$segments = array_map(
			static fn (string $segment): string => self::normalizeGeneratedPathSegment($segment),
			explode('/', trim($path, '/')),
		);

		return implode('/', $segments);
	}

	public static function normalizeGeneratedPathSegment(string $segment): string {
		$segment = mb_scrub($segment, 'UTF-8');
		$segment = str_replace(['<', '>', ':', '"', '/', '\\', '|', '?', '*'], '-', $segment);
		$segment = preg_replace('/[\x00-\x1F\x7F]/', '-', $segment) ?? '';
		$segment = rtrim($segment, ' .');

		if ($segment === '' || $segment === '.' || $segment === '..') {
			$segment = 'untitled';
		}

		// Windows treats these device names as reserved, even when an extension follows.
		$windowsBasename = rtrim(explode('.', $segment, 2)[0], ' .');
		if (preg_match('/^(CON|PRN|AUX|NUL|CONIN\$|CONOUT\$|COM[1-9¹²³]|LPT[1-9¹²³])$/iu', $windowsBasename) === 1
			|| strcasecmp($segment, '.htaccess') === 0) {
			$segment = '_' . $segment;
		}

		// Nextcloud reserves these suffixes for incomplete uploads.
		foreach (['.filepart', '.part'] as $forbiddenExtension) {
			if (str_ends_with(strtolower($segment), $forbiddenExtension)) {
				$segment = substr($segment, 0, -strlen($forbiddenExtension)) . '-' . substr($forbiddenExtension, 1);
				break;
			}
		}

		// Nextcloud's file cache permits at most 250 bytes per filename; keep a file
		// extension intact when shortening long generated file names.
		$extension = self::getFileExtension($segment);
		$name = $extension === '' ? $segment : substr($segment, 0, -strlen($extension));
		$extensionBytes = min(strlen($extension), 250);
		$extension = mb_strcut($extension, 0, $extensionBytes, 'UTF-8');
		$name = mb_strcut($name, 0, 250 - strlen($extension), 'UTF-8');

		return rtrim($name . $extension, ' .') ?: 'untitled';
	}

	public static function normalizeKeyNameForGeneratedPath(string $name): string {
		$name = mb_scrub($name, 'UTF-8');
		$name = preg_replace('/([A-Ga-g])#(?=(?:m|M)?(?:\s|$|[,0-9]))/u', '$1♯', $name) ?? $name;

		return preg_replace('/([A-Ga-g])b(?=(?:m|M)?(?:\s|$|[,0-9]))/u', '$1♭', $name) ?? $name;
	}

	/**
	 * Extract non-empty string properties (like 'name' or 'title') from an entity list.
	 *
	 * @param array<object> $entities
	 * @return list<string>
	 */
	public static function extractNonEmptyEntityNames(array $entities, string $property = 'name'): array {
		$names = [];
		foreach ($entities as $entity) {
			$val = $entity->{$property} ?? '';
			if ($val !== '') {
				$names[] = $val;
			}
		}
		return $names;
	}

	/**
	 * Sort strings by length descending, and alphabetically ascending as tie-breaker.
	 *
	 * @param array<string> $items
	 * @return list<string>
	 */
	public static function sortStringsByLengthDescending(array $items): array {
		$result = array_values($items);
		usort($result, static function (string $a, string $b): int {
			$lenA = mb_strlen($a);
			$lenB = mb_strlen($b);
			if ($lenA !== $lenB) {
				return $lenB <=> $lenA;
			}
			return strcmp($a, $b);
		});
		return $result;
	}

	public static function hasClassicalIndicators(MobileSheetsSong $song): bool {
		if (trim((string)($song->opus ?? '')) !== '' || trim((string)($song->catalogNumber ?? '')) !== '') {
			return true;
		}

		$genreNames = self::extractNonEmptyEntityNames($song->genres);
		foreach ($genreNames as $genre) {
			if (preg_match('/classical|baroque|romantic|renaissance|choral|orchestr|sacred|church|cantata|sonata|symphon|opera/i', $genre) === 1) {
				return true;
			}
		}

		$sourceTypeNames = self::extractNonEmptyEntityNames($song->sourceTypes);
		foreach ($sourceTypeNames as $source) {
			if (preg_match('/classical|urtext|henle|b[aä]renreiter|breitkopf|peters/i', $source) === 1) {
				return true;
			}
		}

		$artistNames = self::extractNonEmptyEntityNames($song->artists);
		foreach ($artistNames as $artist) {
			if (preg_match('/orchestra|philharmonic|philharmonie|orchester|symphony|sinfonie|choir|chor|ensemble|quartet|quartett|quintet|quintett|trio|conductor|dirigent/i', $artist) === 1) {
				return true;
			}
		}

		return false;
	}

	private static function joinEntityNames(array $names): string {
		sort($names, SORT_STRING);
		return implode(', ', array_map(
			static fn (string $name): string => self::normalizeGeneratedPathSegment($name),
			$names,
		));
	}

	/**
	 * @return list<string>
	 */
	private static function buildPathsForEntity(
		string $entityName,
		string $book,
		string $collection,
		string $songTitlePart,
		string $filePrefix,
		string $ext,
	): array {
		if ($entityName === '') {
			return [];
		}

		if ($book !== '') {
			$folder = $entityName . DIRECTORY_SEPARATOR . "{$entityName} - {$book}";
			$fileName = "{$entityName} - {$book} - {$songTitlePart}";
			return [self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext)];
		}

		if ($collection !== '') {
			$folder = $entityName . DIRECTORY_SEPARATOR . "{$entityName} - {$collection}";
			$fileName = "{$entityName} - {$collection} - {$songTitlePart}";
			return [self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext)];
		}

		$folder = $entityName;
		$fileName = "{$entityName} - {$songTitlePart}";
		return [
			self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext),
			self::normalizeGeneratedPath($filePrefix . $fileName . $ext),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function buildPathsForCollectionBook(
		string $collBook,
		string $secondaryEntity,
		string $songTitlePart,
		string $filePrefix,
		string $ext,
	): array {
		if ($collBook === '') {
			return [];
		}

		if ($secondaryEntity !== '') {
			$folder = $collBook . DIRECTORY_SEPARATOR . "{$collBook} - {$secondaryEntity}";
			$fileName = "{$collBook} - {$secondaryEntity} - {$songTitlePart}";
			return [
				self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext),
				self::normalizeGeneratedPath($collBook . DIRECTORY_SEPARATOR . $filePrefix . "{$collBook} - {$songTitlePart}" . $ext),
			];
		}

		$folder = $collBook;
		$fileName = "{$collBook} - {$songTitlePart}";
		return [
			self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext),
			self::normalizeGeneratedPath($filePrefix . $fileName . $ext),
		];
	}

	public static function titleContainsComponent(string $title, string $component): bool {
		$cleanTitle = mb_strtolower(trim($title));
		$cleanComp = mb_strtolower(trim($component));
		if ($cleanComp === '' || $cleanTitle === '') {
			return false;
		}

		if (str_contains($cleanTitle, $cleanComp)) {
			return true;
		}

		$unaccentedTitle = str_replace(['♯', '♭', '.'], ['#', 'b', ''], $cleanTitle);
		$unaccentedComp = str_replace(['♯', '♭', '.'], ['#', 'b', ''], $cleanComp);

		return str_contains($unaccentedTitle, $unaccentedComp);
	}

	public static function buildSongTitlePart(MobileSheetsSong $song): string {
		$songTitle = self::normalizeGeneratedPathSegment($song->title);

		// 1. Key: e.g. "in G", "in C♯m"
		$keyNames = self::extractNonEmptyEntityNames($song->keys);
		sort($keyNames, SORT_STRING);
		$normalizedKeyNames = array_map(
			static fn (string $name): string => self::normalizeGeneratedPathSegment(self::normalizeKeyNameForGeneratedPath($name)),
			$keyNames,
		);
		$keyString = implode(', ', $normalizedKeyNames);

		$keyPart = '';
		if ($keyString !== '') {
			$alreadyHasKey = false;
			foreach ($normalizedKeyNames as $k) {
				if (self::titleContainsComponent($songTitle, 'in ' . $k) || self::titleContainsComponent($songTitle, ' ' . $k)) {
					$alreadyHasKey = true;
					break;
				}
			}
			if (!$alreadyHasKey) {
				$keyPart = " in {$keyString}";
			}
		}

		$result = $songTitle . $keyPart;

		// 2. Catalog number & Opus: e.g. "KV 545", "Op. 27 No. 2"
		$catOpusParts = [];
		$catalog = trim((string)($song->catalogNumber ?? ''));
		if ($catalog !== '' && !self::titleContainsComponent($result, $catalog)) {
			$catOpusParts[] = self::normalizeGeneratedPathSegment($catalog);
		}

		$opus = trim((string)($song->opus ?? ''));
		if ($opus !== '' && !self::titleContainsComponent($result, $opus)) {
			$catOpusParts[] = self::normalizeGeneratedPathSegment($opus);
		}

		if (!empty($catOpusParts)) {
			$result .= ', ' . implode(', ', $catOpusParts);
		}

		// 3. Song / Hymn Number: e.g. "142" -> "(No. 142)"
		$songNumber = trim((string)($song->songNumber ?? ''));
		if ($songNumber !== '' && !self::titleContainsComponent($result, $songNumber)) {
			$normNumber = self::normalizeGeneratedPathSegment($songNumber);
			$formattedNumber = preg_match('/^no\.?\s*/i', $normNumber) === 1 ? $normNumber : "No. {$normNumber}";
			$result .= " ({$formattedNumber})";
		}

		// 4. Year: e.g. "1969" -> "(1969)"
		$yearValues = [];
		foreach ($song->years as $yearObj) {
			$yVal = trim((string)($yearObj->name ?? $yearObj->year ?? ''));
			if ($yVal !== '' && $yVal !== '0') {
				$yearValues[] = $yVal;
			}
		}
		if (!empty($yearValues)) {
			$yearStr = implode(', ', array_unique($yearValues));
			if (!self::titleContainsComponent($result, $yearStr)) {
				$result .= ' (' . self::normalizeGeneratedPathSegment($yearStr) . ')';
			}
		}

		return $result;
	}

	/**
	 * @return list<string> all valid target paths for a song given its composers, artists, books, and collections
	 */
	public static function createTargetPaths(
		MobileSheetsSong $song,
		string $ext,
		?int $fileIndex = null,
		int $fileCount = 1,
		string $preset = self::PRESET_AUTO,
	): array {
		$filePrefix = self::fileIndexPrefix($fileIndex, $fileCount);
		$ext = $ext === '' ? '' : self::normalizeGeneratedPathSegment($ext);

		$composerNames = self::extractNonEmptyEntityNames($song->composers);
		$artistNames = self::extractNonEmptyEntityNames($song->artists);
		$composerStr = self::joinEntityNames($composerNames);
		$artistStr = self::joinEntityNames($artistNames);

		$bookTitles = self::sortStringsByLengthDescending(self::extractNonEmptyEntityNames($song->books, 'title'));
		$collectionNames = self::sortStringsByLengthDescending(self::extractNonEmptyEntityNames($song->collections));

		$book = $bookTitles === [] ? '' : self::normalizeGeneratedPathSegment($bookTitles[0]);
		$collection = $collectionNames === [] ? '' : self::normalizeGeneratedPathSegment($collectionNames[0]);
		$collBook = $book !== '' ? $book : $collection;

		$songTitlePart = self::buildSongTitlePart($song);

		if ($preset === self::PRESET_DISABLED || $preset === 'none') {
			throw new NoNamingRuleException('Naming rule is disabled for this library');
		}

		if ($preset === self::PRESET_COLLECTION) {
			if ($collBook !== '') {
				$sec = $composerStr !== '' ? $composerStr : $artistStr;
				return self::buildPathsForCollectionBook($collBook, $sec, $songTitlePart, $filePrefix, $ext);
			}
			// Fallback to composer or artist if no book/collection
			$primary = $composerStr !== '' ? $composerStr : $artistStr;
			if ($primary !== '') {
				return self::buildPathsForEntity($primary, $book, $collection, $songTitlePart, $filePrefix, $ext);
			}
			throw new NoNamingRuleException('No composer, artist, or collection');
		}

		if ($preset === self::PRESET_ARTIST) {
			if ($artistStr !== '') {
				return self::buildPathsForEntity($artistStr, $book, $collection, $songTitlePart, $filePrefix, $ext);
			}
			if ($composerStr !== '') {
				return self::buildPathsForEntity($composerStr, $book, $collection, $songTitlePart, $filePrefix, $ext);
			}
			if ($collBook !== '') {
				return self::buildPathsForCollectionBook($collBook, '', $songTitlePart, $filePrefix, $ext);
			}
			throw new NoNamingRuleException('No composer, artist, or collection');
		}

		if ($preset === self::PRESET_COMPOSER) {
			if ($composerStr !== '') {
				return self::buildPathsForEntity($composerStr, $book, $collection, $songTitlePart, $filePrefix, $ext);
			}
			if ($artistStr !== '') {
				return self::buildPathsForEntity($artistStr, $book, $collection, $songTitlePart, $filePrefix, $ext);
			}
			if ($collBook !== '') {
				return self::buildPathsForCollectionBook($collBook, '', $songTitlePart, $filePrefix, $ext);
			}
			throw new NoNamingRuleException('No composer, artist, or collection');
		}

		// Default: PRESET_AUTO
		// 1. Both composer and artist are present: smart guess
		if ($composerStr !== '' && $artistStr !== '') {
			$preferComposer = self::hasClassicalIndicators($song);
			$primary = $preferComposer ? $composerStr : $artistStr;
			$secondary = $preferComposer ? $artistStr : $composerStr;

			$primaryPaths = self::buildPathsForEntity($primary, $book, $collection, $songTitlePart, $filePrefix, $ext);
			$secondaryPaths = self::buildPathsForEntity($secondary, $book, $collection, $songTitlePart, $filePrefix, $ext);

			return array_values(array_unique(array_merge($primaryPaths, $secondaryPaths)));
		}

		// 2. Only composer is present
		if ($composerStr !== '') {
			return self::buildPathsForEntity($composerStr, $book, $collection, $songTitlePart, $filePrefix, $ext);
		}

		// 3. Only artist is present
		if ($artistStr !== '') {
			return self::buildPathsForEntity($artistStr, $book, $collection, $songTitlePart, $filePrefix, $ext);
		}

		// 4. Neither composer nor artist, but book or collection is present
		if ($collBook !== '') {
			return self::buildPathsForCollectionBook($collBook, '', $songTitlePart, $filePrefix, $ext);
		}

		throw new NoNamingRuleException('No composer, artist, or collection');
	}

	public static function createTargetPath(
		MobileSheetsSong $song,
		string $ext,
		?int $fileIndex = null,
		int $fileCount = 1,
		string $preset = self::PRESET_AUTO,
	): string {
		$paths = self::createTargetPaths($song, $ext, $fileIndex, $fileCount, $preset);

		return $paths[0];
	}
}
