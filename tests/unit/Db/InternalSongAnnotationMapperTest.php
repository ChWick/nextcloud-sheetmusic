<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAnnotation;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class InternalSongAnnotationMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private InternalSong $song;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('AnnotationMapper Test Lib');
		$this->libraryId = (int)$library->id;
		$this->song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'ann-test-song-uuid',
			libraryId: $this->libraryId,
			title: 'Nocturne in E-flat Major',
		));
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testCrudOperations(): void {
		$songId = (int)$this->song->id;

		// 1. Initially empty
		self::assertEmpty($this->annotationMapper->findForSong($songId));

		// 2. Insert annotation
		$annotation = new InternalSongAnnotation(
			id: null,
			uuid: 'ann-uuid-12345',
			songId: $songId,
			fileId: 101,
			pageNumber: 2,
			layerIndex: 0,
			type: 'drawing',
			x: 0.15,
			y: 0.25,
			width: 0.35,
			height: 0.45,
			style: ['stroke' => '#ff0000', 'strokeWidth' => 2.5],
			data: ['path' => 'M 150 250 L 500 700'],
		);

		$saved = $this->annotationMapper->save($annotation);
		self::assertNotNull($saved->id);
		self::assertSame('ann-uuid-12345', $saved->uuid);
		self::assertSame(2, $saved->pageNumber);

		// 3. Find for song
		$list = $this->annotationMapper->findForSong($songId);
		self::assertCount(1, $list);
		self::assertSame('drawing', $list[0]->type);
		self::assertSame('#ff0000', $list[0]->style['stroke']);
		self::assertSame('M 150 250 L 500 700', $list[0]->data['path']);

		// 4. Find for song file
		$fileList = $this->annotationMapper->findForSongFile($songId, 101);
		self::assertCount(1, $fileList);
		self::assertEmpty($this->annotationMapper->findForSongFile($songId, 999));

		// 5. Find by UUID
		$byUuid = $this->annotationMapper->findByUuid('ann-uuid-12345');
		self::assertNotNull($byUuid);
		self::assertSame($saved->id, $byUuid->id);

		// 6. Update
		$byUuid->type = 'highlight';
		$byUuid->style['opacity'] = 0.5;
		$updated = $this->annotationMapper->save($byUuid);
		self::assertSame('highlight', $updated->type);

		$refetched = $this->annotationMapper->findById((int)$saved->id);
		self::assertNotNull($refetched);
		self::assertSame('highlight', $refetched->type);
		self::assertEqualsWithDelta(0.5, (float)$refetched->style['opacity'], 0.01);

		// 7. Soft Delete
		$this->annotationMapper->delete((int)$saved->id, soft: true);
		self::assertEmpty($this->annotationMapper->findForSong($songId, includeDeleted: false));

		$deletedList = $this->annotationMapper->findForSong($songId, includeDeleted: true);
		self::assertCount(1, $deletedList);
		self::assertNotNull($deletedList[0]->deletedAt);
	}

	public function testFindForFileWithoutSongId(): void {
		$ann = new InternalSongAnnotation(
			id: null,
			uuid: 'setlist-file-ann-1',
			songId: 0,
			fileId: 7788,
			pageNumber: 0,
			layerIndex: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.2,
			width: 0.3,
			height: 0.4,
			style: ['color' => '#e53935'],
			data: ['points' => [[0.1, 0.2], [0.4, 0.6]]],
		);
		$saved = $this->annotationMapper->save($ann);

		$found = $this->annotationMapper->findForFile(7788);
		self::assertCount(1, $found);
		self::assertSame('setlist-file-ann-1', $found[0]->uuid);
		self::assertSame(0, $found[0]->songId);

		$this->annotationMapper->delete((int)$saved->id, soft: true);
		self::assertEmpty($this->annotationMapper->findForFile(7788, includeDeleted: false));
		self::assertCount(1, $this->annotationMapper->findForFile(7788, includeDeleted: true));
	}
}
