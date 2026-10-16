<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Service\MobileSheetsTableHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class MobileSheetsTableHelperTest extends TestCase {
	private PDO $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = new PDO('sqlite::memory:', options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}

	public function testTableExists(): void {
		self::assertFalse(MobileSheetsTableHelper::tableExists($this->db, 'Songs'));

		$this->db->exec('CREATE TABLE Songs (Id INTEGER PRIMARY KEY, Title TEXT)');
		self::assertTrue(MobileSheetsTableHelper::tableExists($this->db, 'Songs'));
	}

	public function testResolveTableWithVariants(): void {
		self::assertNull(MobileSheetsTableHelper::resolveTable($this->db, 'Key'));

		// Case 1: Key exists
		$this->db->exec('CREATE TABLE "Key" (Id INTEGER PRIMARY KEY, Name TEXT)');
		self::assertSame('Key', MobileSheetsTableHelper::resolveTable($this->db, 'Key'));
		self::assertSame('Key', MobileSheetsTableHelper::resolveTable($this->db, 'Keys'));

		// Case 2: SourceTypes exists
		$this->db->exec('CREATE TABLE SourceTypes (Id INTEGER PRIMARY KEY, Type TEXT)');
		self::assertSame('SourceTypes', MobileSheetsTableHelper::resolveTable($this->db, 'SourceType'));
		self::assertSame('SourceTypes', MobileSheetsTableHelper::resolveTable($this->db, 'SourceTypes'));
	}

	public function testGetTableColumns(): void {
		$this->db->exec('CREATE TABLE Test (Id INTEGER PRIMARY KEY, MyColumn TEXT, AnotherCol INTEGER)');
		$cols = MobileSheetsTableHelper::getTableColumns($this->db, 'Test');

		self::assertArrayHasKey('id', $cols);
		self::assertArrayHasKey('mycolumn', $cols);
		self::assertSame('MyColumn', $cols['mycolumn']);
		self::assertSame('AnotherCol', $cols['anothercol']);
	}

	public function testGetSongNotesContentColumn(): void {
		self::assertNull(MobileSheetsTableHelper::getSongNotesContentColumn($this->db));

		$this->db->exec('CREATE TABLE SongNotes (Id INTEGER PRIMARY KEY, SongId INTEGER, Notes TEXT)');
		self::assertSame('Notes', MobileSheetsTableHelper::getSongNotesContentColumn($this->db));
	}

	public function testResolveEntityNameColumn(): void {
		$cols = ['title' => 'Title', 'id' => 'Id'];
		self::assertSame('Title', MobileSheetsTableHelper::resolveEntityNameColumn($cols, 'Name'));
		self::assertNull(MobileSheetsTableHelper::resolveEntityNameColumn(['id' => 'Id'], 'Custom'));
	}
}
