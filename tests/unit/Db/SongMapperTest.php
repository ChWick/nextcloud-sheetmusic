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
		$database->exec("INSERT INTO Songs (Title, SortTitle) VALUES ('Zebra', 'Zebra'), ('Alpha', 'Alpha')");
	}

	protected function tearDown(): void {
		unlink($this->databasePath);
	}

	public function testFindTitlesReturnsSortedSongTitles(): void {
		$this->assertSame(['Alpha', 'Zebra'], (new SongMapper())->findTitles($this->databasePath));
	}
}