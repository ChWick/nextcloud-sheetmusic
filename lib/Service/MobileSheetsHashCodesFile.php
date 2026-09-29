<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

use Psr\Log\LoggerInterface;

/**
 * Reads and rewrites a MobileSheets hash codes file.
 *
 * For a library at "PATH/LIBRARY_NAME.db" the file lives at
 * "PATH/LIBRARY_NAME_hashcodes.txt" and holds one {@see MobileSheetsHashCode}
 * per four lines.
 *
 * Edits are applied in memory and only written out on {@see save()}, so a run
 * that renames many files touches the file once.
 */
class MobileSheetsHashCodesFile {
	public const SUFFIX = '_hashcodes.txt';

	/** Lines per entry: path, hash, modification time, size. */
	private const LINES_PER_ENTRY = 4;

	private const LINE_ENDING = "\n";

	/** @var list<MobileSheetsHashCode> */
	private array $entries = [];

	private bool $dirty = false;

	private function __construct(
		private string $filePath,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Hash codes file that belongs to a library database, e.g.
	 * "/x/Noten/mobilesheets.db" => "/x/Noten/mobilesheets_hashcodes.txt".
	 */
	public static function pathForDatabase(string $databasePath): string {
		$base = str_ends_with($databasePath, '.db')
			? substr($databasePath, 0, -3)
			: $databasePath;

		return $base . self::SUFFIX;
	}

	/**
	 * Parse the hash codes file of a library, or null when there is none.
	 *
	 * A missing file is normal: MobileSheets only writes it once it has hashed
	 * the library's files.
	 */
	public static function forDatabase(
		string $databasePath,
		LoggerInterface $logger,
	): ?self {
		$filePath = self::pathForDatabase($databasePath);
		if (!is_file($filePath)) {
			$logger->debug('No MobileSheets hash codes file for library', [
				'databasePath' => $databasePath,
				'expectedPath' => $filePath,
			]);

			return null;
		}

		return self::parse($filePath, $logger);
	}

	public static function parse(string $filePath, LoggerInterface $logger): self {
		$file = new self($filePath, $logger);

		$contents = file_get_contents($filePath);
		if ($contents === false) {
			throw new \RuntimeException("Could not read MobileSheets hash codes file '$filePath'");
		}
		if ($contents === '') {
			return $file;
		}

		// Split on any newline style and drop the trailing empty element an
		// ending newline would produce.
		$lines = preg_split('/\r\n|\n|\r/', $contents);
		if ($lines === false) {
			throw new \RuntimeException("Could not split MobileSheets hash codes file '$filePath'");
		}
		if (end($lines) === '') {
			array_pop($lines);
		}

		if (count($lines) % self::LINES_PER_ENTRY !== 0) {
			// Writing the file back would silently drop the trailing lines, so
			// refuse rather than lose data.
			throw new \RuntimeException(sprintf(
				'MobileSheets hash codes file \'%s\' is malformed: %d lines is not a multiple of %d',
				$filePath,
				count($lines),
				self::LINES_PER_ENTRY,
			));
		}

		for ($i = 0; $i < count($lines); $i += self::LINES_PER_ENTRY) {
			$file->entries[] = new MobileSheetsHashCode(
				trim($lines[$i]),
				trim($lines[$i + 1]),
				trim($lines[$i + 2]),
				trim($lines[$i + 3]),
			);
		}

		return $file;
	}

	/** @return list<MobileSheetsHashCode> */
	public function entries(): array {
		return $this->entries;
	}

	public function hasPath(string $path): bool {
		foreach ($this->entries as $entry) {
			if ($entry->path === $path) {
				return true;
			}
		}

		return false;
	}

	public function isDirty(): bool {
		return $this->dirty;
	}

	/**
	 * Point an entry at a new path, used after a file has been moved.
	 *
	 * The hash, modification time and size describe the file's content, which a
	 * rename does not change, so only the path is rewritten.
	 *
	 * @return bool whether an entry was updated
	 */
	public function updatePath(string $oldPath, string $newPath): bool {
		if ($oldPath === $newPath) {
			return false;
		}

		$kept = [];
		$updated = false;
		$droppedStale = false;

		foreach ($this->entries as $entry) {
			if ($entry->path === $newPath) {
				// A move only happens when the target did not exist, so any entry
				// already claiming it is stale. Drop it to keep one entry per path.
				$droppedStale = true;
				continue;
			}

			if (!$updated && $entry->path === $oldPath) {
				$kept[] = $entry->withPath($newPath);
				$updated = true;
				continue;
			}

			$kept[] = $entry;
		}

		if (!$updated) {
			return false;
		}

		$this->entries = $kept;
		$this->dirty = true;

		if ($droppedStale) {
			$this->logger->warning('Dropped a stale MobileSheets hash code entry for the target path', [
				'file' => $this->filePath,
				'stalePath' => $newPath,
			]);
		}

		return true;
	}

	/**
	 * Drop the entry for a path, used after a file has been deleted.
	 *
	 * @return bool whether an entry was removed
	 */
	public function removePath(string $path): bool {
		$kept = [];
		$removed = false;

		foreach ($this->entries as $entry) {
			if ($entry->path === $path) {
				$removed = true;
				continue;
			}
			$kept[] = $entry;
		}

		if ($removed) {
			$this->entries = $kept;
			$this->dirty = true;
		}

		return $removed;
	}

	/**
	 * Point an entry at new content facts, used after a file has been rewritten
	 * in place. A rewrite changes the hash, modification time and size of the
	 * content - unlike a rename, which leaves them alone.
	 *
	 * @return bool whether an entry was updated
	 */
	public function updateFacts(string $path, string $hashCode, string $modifiedAt, string $size): bool {
		$kept = [];
		$updated = false;

		foreach ($this->entries as $entry) {
			if (!$updated && $entry->path === $path) {
				$kept[] = new MobileSheetsHashCode($path, $hashCode, $modifiedAt, $size);
				$updated = true;
				continue;
			}
			$kept[] = $entry;
		}

		if ($updated) {
			$this->entries = $kept;
			$this->dirty = true;
		}

		return $updated;
	}

	/** Write the file back, atomically so a failure cannot truncate it. */
	public function save(): void {
		if (!$this->dirty) {
			return;
		}

		$lines = [];
		foreach ($this->entries as $entry) {
			$lines[] = $entry->path;
			$lines[] = $entry->hashCode;
			$lines[] = $entry->modifiedAt;
			$lines[] = $entry->size;
		}

		// MobileSheets writes no trailing newline; keep that convention.
		$contents = implode(self::LINE_ENDING, $lines);

		$temporaryPath = $this->filePath . '.tmp';
		if (file_put_contents($temporaryPath, $contents) === false) {
			throw new \RuntimeException("Could not write MobileSheets hash codes file '$temporaryPath'");
		}
		if (!rename($temporaryPath, $this->filePath)) {
			@unlink($temporaryPath);

			throw new \RuntimeException("Could not replace MobileSheets hash codes file '{$this->filePath}'");
		}

		$this->dirty = false;

		$this->logger->info('Updated MobileSheets hash codes file', [
			'file' => $this->filePath,
			'entries' => count($this->entries),
		]);
	}
}
