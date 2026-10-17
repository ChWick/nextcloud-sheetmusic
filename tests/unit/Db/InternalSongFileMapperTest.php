<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use TestSupport\RealMobileSheetsTestTrait;
use PHPUnit\Framework\TestCase;

final class InternalSongFileMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private InternalSong $song;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('SongFileMapper Test Lib');
		$this->libraryId = (int)$library->id;
		$this->song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'test-song-uuid',
			libraryId: $this->libraryId,
			title: 'Song For File Tests',
		));
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testInsertAndFindByIdAndUuid(): void {
		$file = new InternalSongFile(
			id: null,
			uuid: 'file-uuid-1',
			songId: (int)$this->song->id,
			fileId: 4567,
			pageOrder: '1-3',
			pageCount: 3,
			fileSize: 102400,
			fileHash: 12345678,
			type: InternalSongFile::FILE_TYPE_PDF,
			clientId: 1,
		);

		$saved = $this->songFileMapper->insert($file);

		self::assertNotNull($saved->id);
		self::assertGreaterThan(0, $saved->id);
		self::assertSame('file-uuid-1', $saved->uuid);
		self::assertSame((int)$this->song->id, $saved->songId);
		self::assertSame(4567, $saved->fileId);
		self::assertSame('1-3', $saved->pageOrder);
		self::assertSame(3, $saved->pageCount);
		self::assertSame(102400, $saved->fileSize);
		self::assertSame(12345678, $saved->fileHash);
		self::assertSame(InternalSongFile::FILE_TYPE_PDF, $saved->type);
		self::assertSame(1, $saved->clientId);
		self::assertGreaterThan(0, $saved->createdAt);
		self::assertGreaterThan(0, $saved->updatedAt);
		self::assertNull($saved->deletedAt);

		$byUuid = $this->songFileMapper->findByUuid('file-uuid-1');
		self::assertNotNull($byUuid);
		self::assertSame($saved->id, $byUuid->id);

		$byId = $this->songFileMapper->findById((int)$saved->id);
		self::assertNotNull($byId);
		self::assertSame('file-uuid-1', $byId->uuid);

		self::assertNull($this->songFileMapper->findById(999999));
		self::assertNull($this->songFileMapper->findByUuid('non-existent'));
	}

	public function testFindAllForSong(): void {
		$song2 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's2',
			libraryId: $this->libraryId,
			title: 'Song 2',
		));

		$f1 = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f1',
			songId: (int)$this->song->id,
			fileId: 101,
		));
		$f2 = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f2',
			songId: (int)$this->song->id,
			fileId: 102,
		));
		$this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f3',
			songId: (int)$song2->id,
			fileId: 103,
		));

		$files = $this->songFileMapper->findAllForSong((int)$this->song->id);
		self::assertCount(2, $files);
		self::assertSame([$f1->id, $f2->id], array_map(fn($f) => $f->id, $files));

		// Soft delete f1
		$this->songFileMapper->delete((int)$f1->id);

		$activeFiles = $this->songFileMapper->findAllForSong((int)$this->song->id, includeDeleted: false);
		self::assertCount(1, $activeFiles);
		self::assertSame($f2->id, $activeFiles[0]->id);

		$allFiles = $this->songFileMapper->findAllForSong((int)$this->song->id, includeDeleted: true);
		self::assertCount(2, $allFiles);
	}

	public function testFindByFileId(): void {
		$f1 = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f1',
			songId: (int)$this->song->id,
			fileId: 500,
		));

		$results = $this->songFileMapper->findByFileId(500);
		self::assertCount(1, $results);
		self::assertSame($f1->id, $results[0]->id);

		self::assertEmpty($this->songFileMapper->findByFileId(9999));
	}

	public function testFindByClientId(): void {
		$this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f1',
			songId: (int)$this->song->id,
			fileId: 101,
			clientId: 7,
		));

		$found = $this->songFileMapper->findByClientId((int)$this->song->id, 7);
		self::assertNotNull($found);
		self::assertSame('f1', $found->uuid);

		self::assertNull($this->songFileMapper->findByClientId((int)$this->song->id, 999));
	}

	public function testUpdate(): void {
		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f1',
			songId: (int)$this->song->id,
			fileId: 101,
			pageCount: 1,
		));

		$file->pageCount = 5;
		$file->pageOrder = '1,2,3,4,5';
		$file->fileSize = 2048;
		$file->type = InternalSongFile::FILE_TYPE_PDF;
		$file->clientId = 42;

		$this->songFileMapper->update($file);

		$reloaded = $this->songFileMapper->findById((int)$file->id);
		self::assertNotNull($reloaded);
		self::assertSame(5, $reloaded->pageCount);
		self::assertSame('1,2,3,4,5', $reloaded->pageOrder);
		self::assertSame(2048, $reloaded->fileSize);
		self::assertSame(InternalSongFile::FILE_TYPE_PDF, $reloaded->type);
		self::assertSame(42, $reloaded->clientId);
	}

	public function testDeleteAndRestore(): void {
		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f1',
			songId: (int)$this->song->id,
			fileId: 101,
		));

		// Soft delete
		self::assertTrue($this->songFileMapper->delete((int)$file->id, hardDelete: false));
		$softDeleted = $this->songFileMapper->findById((int)$file->id);
		self::assertNotNull($softDeleted);
		self::assertNotNull($softDeleted->deletedAt);

		// Hard delete
		self::assertTrue($this->songFileMapper->delete((int)$file->id, hardDelete: true));
		self::assertNull($this->songFileMapper->findById((int)$file->id));
	}

	public function testDeleteAllForSong(): void {
		$f1 = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f1',
			songId: (int)$this->song->id,
			fileId: 101,
		));
		$f2 = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f2',
			songId: (int)$this->song->id,
			fileId: 102,
		));

		// Soft delete all
		$this->songFileMapper->deleteAllForSong((int)$this->song->id, hardDelete: false);
		self::assertEmpty($this->songFileMapper->findAllForSong((int)$this->song->id, includeDeleted: false));
		self::assertCount(2, $this->songFileMapper->findAllForSong((int)$this->song->id, includeDeleted: true));

		// Hard delete all
		$this->songFileMapper->deleteAllForSong((int)$this->song->id, hardDelete: true);
		self::assertEmpty($this->songFileMapper->findAllForSong((int)$this->song->id, includeDeleted: true));
	}

	public function testFindAllForLibrary(): void {
		$this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f1',
			songId: (int)$this->song->id,
			fileId: 101,
		));

		$files = $this->songFileMapper->findAllForLibrary($this->libraryId);
		self::assertCount(1, $files);
		self::assertSame('f1', $files[0]->uuid);
	}

	public function testFindAndRemoveOrphanSongFiles(): void {
		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'orphan-candidate',
			songId: (int)$this->song->id,
			fileId: 999,
		));

		// Initially, song is not deleted, so no orphans
		self::assertEmpty($this->songFileMapper->findOrphanSongFiles($this->libraryId));

		// Soft-delete the song
		$this->songMapper->delete((int)$this->song->id);

		// Now file is an orphan
		$orphans = $this->songFileMapper->findOrphanSongFiles($this->libraryId);
		self::assertCount(1, $orphans);
		self::assertSame((int)$file->id, $orphans[0]['id']);
		self::assertSame((int)$this->song->id, $orphans[0]['songId']);
		self::assertSame(999, $orphans[0]['fileId']);

		// Remove orphan song files (soft deletes the file records)
		$removed = $this->songFileMapper->removeOrphanSongFiles($this->libraryId);
		self::assertSame(1, $removed);

		// Now no more orphan files to fix
		self::assertEmpty($this->songFileMapper->findOrphanSongFiles($this->libraryId));

		// File is now soft-deleted
		$deletedFile = $this->songFileMapper->findById((int)$file->id);
		self::assertNotNull($deletedFile);
		self::assertNotNull($deletedFile->deletedAt);
	}
}
