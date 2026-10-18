<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\MobileSheetsFile;
use OCA\SheetMusic\Db\MobileSheetsSong;

class SongPathNamingRule {
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

	/**
	 * @return list<string> all valid target paths for a song given its composers, artists, books, and collections
	 */
	public static function createTargetPaths(
		MobileSheetsSong $song,
		string $ext,
		?int $fileIndex = null,
		int $fileCount = 1,
	): array {
		$filePrefix = self::fileIndexPrefix($fileIndex, $fileCount);
		$ext = $ext === '' ? '' : self::normalizeGeneratedPathSegment($ext);

		// 1. Composers and Artists: sort alphabetically and join full list
		$compArtistNames = self::extractNonEmptyEntityNames($song->composers);
		if (empty($compArtistNames)) {
			$compArtistNames = self::extractNonEmptyEntityNames($song->artists);
		}
		sort($compArtistNames, SORT_STRING);
		$compArtist = implode(', ', array_map(
			static fn (string $name): string => self::normalizeGeneratedPathSegment($name),
			$compArtistNames,
		));

		// 2. Books and Collections: sort by length descending (longest string first), then alphabetically ascending
		$bookTitles = self::sortStringsByLengthDescending(self::extractNonEmptyEntityNames($song->books, 'title'));
		$collectionNames = self::sortStringsByLengthDescending(self::extractNonEmptyEntityNames($song->collections));

		$book = $bookTitles === [] ? '' : self::normalizeGeneratedPathSegment($bookTitles[0]);
		$collection = $collectionNames === [] ? '' : self::normalizeGeneratedPathSegment($collectionNames[0]);

		$keyNames = self::extractNonEmptyEntityNames($song->keys);
		sort($keyNames, SORT_STRING);
		$keyString = implode(', ', array_map(
			static fn (string $name): string => self::normalizeGeneratedPathSegment(self::normalizeKeyNameForGeneratedPath($name)),
			$keyNames,
		));

		$songTitle = self::normalizeGeneratedPathSegment($song->title);
		$songTitlePart = $keyString !== '' ? "{$songTitle} in {$keyString}" : $songTitle;

		if ($compArtist !== '') {
			if ($book !== '') {
				$folder = $compArtist . DIRECTORY_SEPARATOR . "{$compArtist} - {$book}";
				$fileName = "{$compArtist} - {$book} - {$songTitlePart}";
				return [self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext)];
			}
			if ($collection !== '') {
				$folder = $compArtist . DIRECTORY_SEPARATOR . "{$compArtist} - {$collection}";
				$fileName = "{$compArtist} - {$collection} - {$songTitlePart}";
				return [self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext)];
			}
			$folder = $compArtist;
			$fileName = "{$compArtist} - {$songTitlePart}";
			return [
				self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext),
				self::normalizeGeneratedPath($filePrefix . $fileName . $ext),
			];
		}
		if ($collection !== '') {
			$folder = $collection;
			$fileName = "{$collection} - {$songTitlePart}";
			return [
				self::normalizeGeneratedPath($folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext),
				self::normalizeGeneratedPath($filePrefix . $fileName . $ext),
			];
		}
		throw new NoNamingRuleException('No composer, artist, or collection');
	}

	public static function createTargetPath(
		MobileSheetsSong $song,
		string $ext,
		?int $fileIndex = null,
		int $fileCount = 1,
	): string {
		$paths = self::createTargetPaths($song, $ext, $fileIndex, $fileCount);

		return $paths[0];
	}
}
