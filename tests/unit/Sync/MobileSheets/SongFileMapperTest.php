<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileFacts;
use OCA\SheetMusic\Sync\MobileSheets\SongFileMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SongFileMapperTest extends TestCase {
	private string $databasePath;
	private SongFileMapper $fileMapper;

	protected function setUp(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'sheetmusic-file-test-');
		self::assertNotFalse($databasePath);
		$this->databasePath = $databasePath;

		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER, Height INTEGER)');
		$database->exec("INSERT INTO Files (Id, SongId, Path, LastModified) VALUES (1, 10, '/path/to/song.pdf', 1000)");

		$this->fileMapper = new SongFileMapper(new NullLogger());
	}

	protected function tearDown(): void {
		if (file_exists($this->databasePath)) {
			unlink($this->databasePath);
		}
	}

	public function testDetermineFileType(): void {
		self::assertSame(SongFileMapper::FILE_TYPE_PDF, SongFileMapper::determineFileType('test.pdf'));
		self::assertSame(SongFileMapper::FILE_TYPE_IMAGE, SongFileMapper::determineFileType('test.png'));
		self::assertSame(SongFileMapper::FILE_TYPE_TEXT, SongFileMapper::determineFileType('test.cho'));
		self::assertSame(SongFileMapper::FILE_TYPE_ABC, SongFileMapper::determineFileType('test.abc'));
		self::assertSame(SongFileMapper::FILE_TYPE_OTHER, SongFileMapper::determineFileType('test.bin'));
	}

	public function testFilesOfSongReturnsFiles(): void {
		$files = $this->fileMapper->filesOfSong($this->databasePath, 10);
		self::assertCount(1, $files);
		self::assertSame(1, $files[0]->id);
		self::assertSame('/path/to/song.pdf', $files[0]->path);

		$emptyFiles = $this->fileMapper->filesOfSong($this->databasePath, 99);
		self::assertEmpty($emptyFiles);
	}

	public function testSetSongFileAndRemoveSongFile(): void {
		$facts = new MobileSheetsFileFacts(
			pageOrder: '1-2',
			fileSize: 1024,
			lastModified: 2000,
			sourceFilePageCount: 2,
			fileHash: 12345,
		);

		$this->fileMapper->setSongFile($this->databasePath, 10, '/path/to/newfile.pdf', $facts);
		$files = $this->fileMapper->filesOfSong($this->databasePath, 10);
		self::assertCount(2, $files);

		$removedPath = $this->fileMapper->removeSongFile($this->databasePath, 10, 2);
		self::assertSame('/path/to/newfile.pdf', $removedPath);

		$filesAfterRemove = $this->fileMapper->filesOfSong($this->databasePath, 10);
		self::assertCount(1, $filesAfterRemove);

		$nullRemove = $this->fileMapper->removeSongFile($this->databasePath, 10, 999);
		self::assertNull($nullRemove);
	}

	public function testUpdateFile(): void {
		$facts = new MobileSheetsFileFacts(
			pageOrder: '1-4',
			fileSize: 2048,
			lastModified: 3000,
			sourceFilePageCount: 4,
			fileHash: 54321,
		);

		$this->fileMapper->updateFile($this->databasePath, 1, '/path/to/renamed.pdf', $facts);
		$files = $this->fileMapper->filesOfSong($this->databasePath, 10);
		self::assertCount(1, $files);
		self::assertSame('/path/to/renamed.pdf', $files[0]->path);
	}
}
