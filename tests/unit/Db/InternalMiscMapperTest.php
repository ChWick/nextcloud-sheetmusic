<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSong;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class InternalMiscMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('MiscMapper Test Lib');
		$this->libraryId = (int)$library->id;
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testSaveAndGetMiscSchemas(): void {
		$this->miscMapper->saveMiscSchema($this->libraryId, 'mobilesheets', 'Setlists', 'CREATE TABLE Setlists (Id INT);');
		$this->miscMapper->saveMiscSchema($this->libraryId, 'mobilesheets', 'Bookmarks', 'CREATE TABLE Bookmarks (Id INT);');

		$schemas = $this->miscMapper->getMiscSchemas($this->libraryId, 'mobilesheets');
		self::assertSame([
			'Setlists' => 'CREATE TABLE Setlists (Id INT);',
			'Bookmarks' => 'CREATE TABLE Bookmarks (Id INT);',
		], $schemas);

		// Update existing schema
		$this->miscMapper->saveMiscSchema($this->libraryId, 'mobilesheets', 'Setlists', 'CREATE TABLE Setlists (Id INT, Title TEXT);');
		$updatedSchemas = $this->miscMapper->getMiscSchemas($this->libraryId, 'mobilesheets');
		self::assertSame('CREATE TABLE Setlists (Id INT, Title TEXT);', $updatedSchemas['Setlists']);
	}

	public function testPragmaUserVersion(): void {
		self::assertNull($this->miscMapper->getPragmaUserVersion($this->libraryId, 'mobilesheets'));

		$this->miscMapper->savePragmaUserVersion($this->libraryId, 'mobilesheets', 136);
		self::assertSame(136, $this->miscMapper->getPragmaUserVersion($this->libraryId, 'mobilesheets'));
	}

	public function testSaveAndGetMiscData(): void {
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'Setlists', '1', [
			'Id' => 1,
			'Title' => 'Set 1',
		]);
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'Setlists', '2', [
			'Id' => 2,
			'Title' => 'Set 2',
		]);

		$data = $this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Setlists');
		self::assertCount(2, $data);
		self::assertSame('Set 1', $data[0]['Title']);
		self::assertSame('Set 2', $data[1]['Title']);

		// Update record 1
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'Setlists', '1', [
			'Id' => 1,
			'Title' => 'Updated Set 1',
		]);

		$updatedData = $this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Setlists');
		self::assertCount(2, $updatedData);
		self::assertSame('Updated Set 1', $updatedData[0]['Title']);
	}

	public function testSaveAndRestoreBinaryData(): void {
		$binaryString = "\x00\xFF\x81binary\x00\xFE";
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'CustomBinary', 'bin-1', [
			'raw' => $binaryString,
			'nested' => ['data' => $binaryString],
		]);

		$data = $this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'CustomBinary');
		self::assertCount(1, $data);
		self::assertSame($binaryString, $data[0]['raw']);
		self::assertSame($binaryString, $data[0]['nested']['data']);
	}

	public function testDeleteMiscData(): void {
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'Setlists', 'rec-1', ['Name' => 'Concert']);

		// Soft delete
		self::assertTrue($this->miscMapper->deleteMiscData($this->libraryId, 'mobilesheets', 'Setlists', 'rec-1', hardDelete: false));
		self::assertEmpty($this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Setlists', includeDeleted: false));
		self::assertCount(1, $this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Setlists', includeDeleted: true));

		// Hard delete
		self::assertTrue($this->miscMapper->deleteMiscData($this->libraryId, 'mobilesheets', 'Setlists', 'rec-1', hardDelete: true));
		self::assertEmpty($this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Setlists', includeDeleted: true));
	}

	public function testDeleteMiscDataForSong(): void {
		$songUuid = 'song-uuid-xyz';
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'Annotations', 'a1', ['val' => 1], songUuid: $songUuid);
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'Annotations', 'a2', ['val' => 2], songUuid: $songUuid);

		// Soft delete
		$count = $this->miscMapper->deleteMiscDataForSong($this->libraryId, 'mobilesheets', $songUuid, hardDelete: false);
		self::assertSame(2, $count);
		self::assertEmpty($this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Annotations', includeDeleted: false));

		// Hard delete
		$hardCount = $this->miscMapper->deleteMiscDataForSong($this->libraryId, 'mobilesheets', $songUuid, hardDelete: true);
		self::assertSame(2, $hardCount);
		self::assertEmpty($this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Annotations', includeDeleted: true));

		// Null songUuid returns 0
		self::assertSame(0, $this->miscMapper->deleteMiscDataForSong($this->libraryId, 'mobilesheets', null));
	}

	public function testDeleteAllMiscDataForLibrary(): void {
		$this->miscMapper->saveMiscSchema($this->libraryId, 'mobilesheets', 'Setlists', 'CREATE TABLE Setlists (Id INT);');
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'Setlists', '1', ['Id' => 1]);

		$this->miscMapper->deleteAllMiscDataForLibrary($this->libraryId);

		self::assertEmpty($this->miscMapper->getMiscSchemas($this->libraryId, 'mobilesheets'));
		self::assertEmpty($this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Setlists', includeDeleted: true));
	}

	public function testFindAndRemoveOrphanMiscData(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'orphan-song-uuid',
			libraryId: $this->libraryId,
			title: 'Orphan Song Candidate',
		));

		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'Bookmarks', 'b1', [
			'Name' => 'Page 1',
		], songUuid: 'orphan-song-uuid');

		// Active song -> not an orphan
		self::assertEmpty($this->miscMapper->findOrphanMiscData($this->libraryId));

		// Soft delete the song
		$this->songMapper->delete((int)$song->id);

		$orphanIds = $this->miscMapper->findOrphanMiscData($this->libraryId);
		self::assertCount(1, $orphanIds);

		$removed = $this->miscMapper->removeOrphanMiscData($this->libraryId);
		self::assertSame(1, $removed);

		self::assertEmpty($this->miscMapper->findOrphanMiscData($this->libraryId));
		self::assertEmpty($this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'Bookmarks', includeDeleted: false));
	}

	public function testSaveAndGetMiscDataWithBinaryBlobPreservesBytes(): void {
		$binaryWithNulls = "\x00\x01\x00\xFF\x00\xFE\x10";
		$this->miscMapper->saveMiscData($this->libraryId, 'mobilesheets', 'AnnotationPoints', '1', [
			'Id' => 1,
			'AnnotationId' => 10,
			'Points' => $binaryWithNulls,
			'Count' => 7,
		]);

		$rows = $this->miscMapper->getMiscDataForTable($this->libraryId, 'mobilesheets', 'AnnotationPoints');
		self::assertCount(1, $rows);
		self::assertSame($binaryWithNulls, $rows[0]['Points']);
	}
}
