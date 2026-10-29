<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\SongNoteMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SongNoteMapperTest extends TestCase {
	private string $databasePath;
	private SongNoteMapper $noteMapper;

	protected function setUp(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'sheetmusic-note-test-');
		self::assertNotFalse($databasePath);
		$this->databasePath = $databasePath;

		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('CREATE TABLE SongNotes(Id INTEGER PRIMARY KEY, SongId INTEGER, Note VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec("INSERT INTO SongNotes (Id, SongId, Note) VALUES (1, 10, 'Remember to repeat chorus')");

		$this->noteMapper = new SongNoteMapper(new NullLogger());
	}

	protected function tearDown(): void {
		if (file_exists($this->databasePath)) {
			unlink($this->databasePath);
		}
	}

	public function testNotesOfSong(): void {
		$notes = $this->noteMapper->notesOfSong($this->databasePath, 10);
		self::assertCount(1, $notes);
		self::assertSame('Remember to repeat chorus', $notes[0]->note);
	}

	public function testUpdateNotes(): void {
		$newNotes = [
			['note' => 'Watch tempo change at bar 32'],
			['text' => 'Coda begins at page 3'],
			['note' => '   '], // Empty note skipped
		];

		$this->noteMapper->updateNotes($this->databasePath, 10, $newNotes);
		$notes = $this->noteMapper->notesOfSong($this->databasePath, 10);

		self::assertCount(2, $notes);
		self::assertSame('Watch tempo change at bar 32', $notes[0]->note);
		self::assertSame('Coda begins at page 3', $notes[1]->note);
	}
}
