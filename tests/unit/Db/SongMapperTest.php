<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\MobileSheetsManager\Db\SongMapper;
use PHPUnit\Framework\TestCase;

final class SongMapperTest extends TestCase {
	private string $databasePath;

	protected function setUp(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'mobilesheetsmanager-');
		self::assertNotFalse($databasePath);
		$this->databasePath = $databasePath;
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255))');
		$database->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255))');
		$database->exec("INSERT INTO Songs (Title, SortTitle) VALUES ('Zebra', 'Zebra'), ('Alpha', 'Alpha')");
		$database->exec("INSERT INTO Files (SongId, Path) VALUES (1, '/remote.php/dav/files/admin/Noten/Zebra.pdf'), (2, '/remote.php/dav/files/admin/Noten/Alpha.pdf')");
	}

	protected function tearDown(): void {
		unlink($this->databasePath);
	}

	public function testFindSongsReturnsSortedSongs(): void {
		$this->assertSame([
			['id' => 2, 'title' => 'Alpha', 'filePath' => '/remote.php/dav/files/admin/Noten/Alpha.pdf'],
			['id' => 1, 'title' => 'Zebra', 'filePath' => '/remote.php/dav/files/admin/Noten/Zebra.pdf'],
		], (new SongMapper())->findSongs($this->databasePath));
	}
}