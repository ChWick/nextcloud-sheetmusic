<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\SongAudioMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SongAudioMapperTest extends TestCase {
	private string $databasePath;
	private SongAudioMapper $audioMapper;

	protected function setUp(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'sheetmusic-audio-test-');
		self::assertNotFalse($databasePath);
		$this->databasePath = $databasePath;

		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('CREATE TABLE AudioFiles(Id INTEGER PRIMARY KEY, SongId INTEGER, Title VARCHAR(255), File VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec("INSERT INTO AudioFiles (Id, SongId, Title, File) VALUES (1, 10, 'Backing Track', 'track1.mp3')");

		$this->audioMapper = new SongAudioMapper(new NullLogger());
	}

	protected function tearDown(): void {
		if (file_exists($this->databasePath)) {
			unlink($this->databasePath);
		}
	}

	public function testAudioFilesOfSong(): void {
		$audioFiles = $this->audioMapper->audioFilesOfSong($this->databasePath, 10);
		self::assertCount(1, $audioFiles);
		self::assertSame('Backing Track', $audioFiles[0]->title);
		self::assertSame('track1.mp3', $audioFiles[0]->file);
	}

	public function testUpdateAudioFiles(): void {
		$newAudio = [
			['title' => 'New Track', 'file' => 'track2.mp3'],
			['file' => 'track3.mp3'], // Title inferred from filename
			['file' => ''], // Empty file skipped
		];

		$this->audioMapper->updateAudioFiles($this->databasePath, 10, $newAudio);
		$audioFiles = $this->audioMapper->audioFilesOfSong($this->databasePath, 10);

		self::assertCount(2, $audioFiles);
		self::assertSame('New Track', $audioFiles[0]->title);
		self::assertSame('track2.mp3', $audioFiles[0]->file);
		self::assertSame('track3', $audioFiles[1]->title);
		self::assertSame('track3.mp3', $audioFiles[1]->file);
	}
}
