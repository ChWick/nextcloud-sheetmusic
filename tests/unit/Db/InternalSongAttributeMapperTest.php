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

final class InternalSongAttributeMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private InternalSong $song;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('SongAttributeMapper Test Lib');
		$this->libraryId = (int)$library->id;
		$this->song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'attr-test-song-uuid',
			libraryId: $this->libraryId,
			title: 'Song With Attributes',
		));
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testSetAttributeAndFind(): void {
		$songId = (int)$this->song->id;

		self::assertNull($this->attributeMapper->findForSong($songId, 'tuning'));

		// Insert
		$this->attributeMapper->setAttribute($songId, 'tuning', 'Standard');
		self::assertSame('Standard', $this->attributeMapper->findForSong($songId, 'tuning'));

		// Update existing
		$this->attributeMapper->setAttribute($songId, 'tuning', 'Drop D');
		self::assertSame('Drop D', $this->attributeMapper->findForSong($songId, 'tuning'));
	}

	public function testFindAllForSong(): void {
		$songId = (int)$this->song->id;

		$this->attributeMapper->setAttribute($songId, 'custom', 'Val1');
		$this->attributeMapper->setAttribute($songId, 'custom2', 'Val2');
		$this->attributeMapper->setAttribute($songId, 'tuning', 'Drop D');

		$attrs = $this->attributeMapper->findAllForSong($songId);
		self::assertSame([
			'custom' => 'Val1',
			'custom2' => 'Val2',
			'tuning' => 'Drop D',
		], $attrs);
	}

	public function testRemoveAttribute(): void {
		$songId = (int)$this->song->id;

		$this->attributeMapper->setAttribute($songId, 'tuning', 'Standard');
		self::assertTrue($this->attributeMapper->removeAttribute($songId, 'tuning'));
		self::assertNull($this->attributeMapper->findForSong($songId, 'tuning'));

		// Removing non-existent returns false
		self::assertFalse($this->attributeMapper->removeAttribute($songId, 'tuning'));
	}

	public function testRemoveAllForSong(): void {
		$songId = (int)$this->song->id;

		$this->attributeMapper->setAttribute($songId, 'a1', 'v1');
		$this->attributeMapper->setAttribute($songId, 'a2', 'v2');

		self::assertCount(2, $this->attributeMapper->findAllForSong($songId));

		$this->attributeMapper->removeAllForSong($songId);
		self::assertEmpty($this->attributeMapper->findAllForSong($songId));
	}

	public function testFindAllForLibrary(): void {
		$song2 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's2-uuid',
			libraryId: $this->libraryId,
			title: 'Second Song',
		));

		$s1Id = (int)$this->song->id;
		$s2Id = (int)$song2->id;

		$this->attributeMapper->setAttribute($s1Id, 'key', 'C major');
		$this->attributeMapper->setAttribute($s2Id, 'key', 'G major');
		$this->attributeMapper->setAttribute($s2Id, 'tempo', '120');

		$libAttrs = $this->attributeMapper->findAllForLibrary($this->libraryId);
		self::assertArrayHasKey($s1Id, $libAttrs);
		self::assertArrayHasKey($s2Id, $libAttrs);
		self::assertSame(['key' => 'C major'], $libAttrs[$s1Id]);
		self::assertSame(['key' => 'G major', 'tempo' => '120'], $libAttrs[$s2Id]);
	}

	public function testFindAndRemoveOrphanSongAttributes(): void {
		$songId = (int)$this->song->id;
		$this->attributeMapper->setAttribute($songId, 'custom_orphan', 'Val');

		// Active song -> not an orphan
		self::assertEmpty($this->attributeMapper->findOrphanSongAttributes($this->libraryId));

		// Soft delete the song
		$this->songMapper->delete($songId);

		$orphanIds = $this->attributeMapper->findOrphanSongAttributes($this->libraryId);
		self::assertCount(1, $orphanIds);

		$removed = $this->attributeMapper->removeOrphanSongAttributes($this->libraryId);
		self::assertSame(1, $removed);

		self::assertEmpty($this->attributeMapper->findOrphanSongAttributes($this->libraryId));
		self::assertEmpty($this->attributeMapper->findAllForSong($songId));
	}
}
