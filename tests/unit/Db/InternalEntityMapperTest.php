<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSong;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class InternalEntityMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private InternalEntityMapper $mapper;
	private int $libraryId;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->mapper = $this->entityMapper;
		$library = $this->createTestLibrary();
		$this->libraryId = (int)$library->id;
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testFindByIdReturnsEntity(): void {
		$entity = new InternalEntity(
			id: null,
			uuid: 'entity-uuid-1',
			libraryId: $this->libraryId,
			type: 'instrument',
			name: 'Piano',
		);
		$created = $this->mapper->insert($entity);

		$found = $this->mapper->findById((int)$created->id);
		self::assertNotNull($found);
		self::assertSame((int)$created->id, $found->id);
		self::assertSame('instrument', $found->type);
		self::assertSame('Piano', $found->name);
	}

	public function testFindByNameReturnsEntity(): void {
		$entity = new InternalEntity(
			id: null,
			uuid: 'comp-uuid',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'J. S. Bach',
		);
		$this->mapper->insert($entity);

		$found = $this->mapper->findByName($this->libraryId, 'composer', 'J. S. Bach');
		self::assertNotNull($found);
		self::assertSame('J. S. Bach', $found->name);
		self::assertSame('composer', $found->type);
	}

	public function testInsertSetsIdAndTimestamps(): void {
		$entity = new InternalEntity(
			id: null,
			uuid: 'organ-uuid',
			libraryId: $this->libraryId,
			type: 'instrument',
			name: 'Pipe Organ',
		);
		$saved = $this->mapper->insert($entity);

		self::assertNotNull($saved->id);
		self::assertGreaterThan(0, $saved->id);
		self::assertSame('Pipe Organ', $saved->name);
		self::assertGreaterThan(0, $saved->createdAt);
	}

	public function testFindEntitiesForSong(): void {
		$entity = new InternalEntity(
			id: null,
			uuid: 'u1',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Chopin',
		);
		$createdEntity = $this->mapper->insert($entity);

		$song = new InternalSong(
			id: null,
			uuid: 'song-u1',
			libraryId: $this->libraryId,
			title: 'Nocturne',
		);
		$createdSong = $this->songMapper->insert($song);
		$this->mapper->linkSongEntity((int)$createdSong->id, (int)$createdEntity->id);

		$entities = $this->mapper->findEntitiesForSong((int)$createdSong->id, 'composer');
		self::assertCount(1, $entities);
		self::assertSame('Chopin', $entities[0]->name);
	}

	private function insertRawSongEntityLink(int $songId, int $entityId): void {
		$qb = $this->dbConn->getQueryBuilder();
		$qb->insert('sheetmusic_song_entities')
			->values([
				'song_id' => $qb->createNamedParameter($songId),
				'entity_id' => $qb->createNamedParameter($entityId),
				'created_at' => $qb->createNamedParameter(time()),
			])
			->executeStatement();
	}

	public function testFindDuplicateSongEntityAssignments(): void {
		$entity = $this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'ent-dup',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Mozart',
		));
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'song-dup',
			libraryId: $this->libraryId,
			title: 'Requiem',
		));

		$this->insertRawSongEntityLink((int)$song->id, (int)$entity->id);
		$this->insertRawSongEntityLink((int)$song->id, (int)$entity->id);

		$duplicates = $this->mapper->findDuplicateSongEntityAssignments($this->libraryId);
		self::assertCount(1, $duplicates);
		self::assertSame((int)$song->id, $duplicates[0]['songId']);
		self::assertSame((int)$entity->id, $duplicates[0]['entityId']);
		self::assertSame('composer', $duplicates[0]['entityType']);
		self::assertSame(2, $duplicates[0]['count']);
	}

	public function testRemoveDuplicateSongEntityAssignments(): void {
		$entity = $this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'ent-rem',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Brahms',
		));
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'song-rem',
			libraryId: $this->libraryId,
			title: 'Lullaby',
		));

		$this->insertRawSongEntityLink((int)$song->id, (int)$entity->id);
		$this->insertRawSongEntityLink((int)$song->id, (int)$entity->id);
		$this->insertRawSongEntityLink((int)$song->id, (int)$entity->id);

		$removed = $this->mapper->removeDuplicateSongEntityAssignments($this->libraryId, (int)$song->id);
		self::assertSame(2, $removed);

		$entities = $this->mapper->findEntitiesForSong((int)$song->id, 'composer');
		self::assertCount(1, $entities);
	}

	public function testRemoveDuplicateSongEntityAssignmentsWithoutSongId(): void {
		$removed = $this->mapper->removeDuplicateSongEntityAssignments($this->libraryId);
		self::assertSame(0, $removed);
	}

	public function testFindOrphanSongEntityAssignments(): void {
		$entity = $this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'ent-orph',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Liszt',
		));
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'song-orph',
			libraryId: $this->libraryId,
			title: 'Liebestraum',
		));

		// Case 1: link pointing to non-existent song
		$this->mapper->linkSongEntity(999999, (int)$entity->id);
		// Case 2: link pointing to non-existent entity
		$this->mapper->linkSongEntity((int)$song->id, 999999);

		$orphans = $this->mapper->findOrphanSongEntityAssignments($this->libraryId);
		self::assertCount(2, $orphans);
	}

	public function testRemoveOrphanSongEntityAssignments(): void {
		$entity = $this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'ent-del-orph',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Vivaldi',
		));
		$this->mapper->linkSongEntity(888888, (int)$entity->id);

		$removed = $this->mapper->removeOrphanSongEntityAssignments($this->libraryId);
		self::assertSame(1, $removed);

		$orphans = $this->mapper->findOrphanSongEntityAssignments($this->libraryId);
		self::assertEmpty($orphans);
	}

	public function testFindDuplicateClientIds(): void {
		$e1 = $this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'e1',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Bach',
			clientId: 10,
		));
		$e2 = $this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'e2',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Beethoven',
			clientId: 10,
		));
		$this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'e3',
			libraryId: $this->libraryId,
			type: 'collection',
			name: 'Hymns',
			clientId: 5,
		));

		$conflicts = $this->mapper->findDuplicateClientIds($this->libraryId);
		self::assertCount(1, $conflicts);
		self::assertSame('composer', $conflicts[0]->type);
		self::assertSame(10, $conflicts[0]->clientId);
		self::assertSame([(int)$e1->id, (int)$e2->id], $conflicts[0]->entityIds);
	}

	public function testFixDuplicateClientIds(): void {
		$this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'e1',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Bach',
			clientId: 10,
		));
		$this->mapper->insert(new InternalEntity(
			id: null,
			uuid: 'e2',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Beethoven',
			clientId: 10,
		));

		$fixed = $this->mapper->fixDuplicateClientIds($this->libraryId);
		self::assertSame(1, $fixed);

		$remainingConflicts = $this->mapper->findDuplicateClientIds($this->libraryId);
		self::assertEmpty($remainingConflicts);
	}

	public function testGetLabel(): void {
		self::assertSame('composer', InternalEntityMapper::getLabel('composers'));
		self::assertSame('composers', InternalEntityMapper::getLabel('composer', true));
		self::assertSame('custom group', InternalEntityMapper::getLabel('customgroups'));
		self::assertSame('custom groups', InternalEntityMapper::getLabel('customgroups', true));
		self::assertSame('time signature', InternalEntityMapper::getLabel('signatures'));
		self::assertSame('time signatures', InternalEntityMapper::getLabel('signature', true));
		self::assertSame('source type', InternalEntityMapper::getLabel('sourcetype'));
		self::assertSame('source types', InternalEntityMapper::getLabel('sourcetypes', true));
	}
}
