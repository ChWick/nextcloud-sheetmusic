<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class InternalEntityMapperTest extends TestCase {
	public function testFindByIdReturnsEntity(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturn([
			'id' => 15,
			'uuid' => 'entity-uuid-1',
			'library_id' => 1,
			'type' => 'instrument',
			'name' => 'Piano',
			'sort_by' => 1,
			'ascending' => 1,
			'client_id' => null,
			'created_at' => 1000,
			'updated_at' => 2000,
			'deleted_at' => null,
		]);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalEntityMapper($db);
		$entity = $mapper->findById(15);

		self::assertNotNull($entity);
		self::assertSame(15, $entity->id);
		self::assertSame('instrument', $entity->type);
		self::assertSame('Piano', $entity->name);
	}

	public function testFindByNameReturnsEntity(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturn([
			'id' => 20,
			'uuid' => 'comp-uuid',
			'library_id' => 1,
			'type' => 'composer',
			'name' => 'J. S. Bach',
			'sort_by' => 1,
			'ascending' => 1,
			'client_id' => 5,
			'created_at' => 100,
			'updated_at' => 100,
			'deleted_at' => null,
		]);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalEntityMapper($db);
		$entity = $mapper->findByName(1, 'composer', 'J. S. Bach');

		self::assertNotNull($entity);
		self::assertSame('J. S. Bach', $entity->name);
		self::assertSame('composer', $entity->type);
	}

	public function testInsertSetsIdAndTimestamps(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('lastInsertId')->willReturn(99);

		$entity = new InternalEntity(
			id: null,
			uuid: 'organ-uuid',
			libraryId: 1,
			type: 'instrument',
			name: 'Pipe Organ',
		);

		$mapper = new InternalEntityMapper($db);
		$saved = $mapper->insert($entity);

		self::assertSame(99, $saved->id);
		self::assertSame('Pipe Organ', $saved->name);
		self::assertGreaterThan(0, $saved->createdAt);
	}

	public function testFindEntitiesForSong(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			[
				'id' => 1,
				'uuid' => 'u1',
				'library_id' => 1,
				'type' => 'composer',
				'name' => 'Chopin',
			],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalEntityMapper($db);
		$entities = $mapper->findEntitiesForSong(10, 'composer');

		self::assertCount(1, $entities);
		self::assertSame('Chopin', $entities[0]->name);
	}

	public function testFindDuplicateSongEntityAssignments(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			[
				'song_id' => 10,
				'entity_id' => 25,
				'type' => 'composer',
				'cnt' => 3,
			],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$queryFunction = $this->createMock(IQueryFunction::class);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('gt')->willReturn('gt');
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturn($queryFunction);
		$qb->method('select')->willReturnSelf();
		$qb->method('selectAlias')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('groupBy')->willReturnSelf();
		$qb->method('having')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalEntityMapper($db);
		$duplicates = $mapper->findDuplicateSongEntityAssignments(1);

		self::assertCount(1, $duplicates);
		self::assertSame(10, $duplicates[0]['songId']);
		self::assertSame(25, $duplicates[0]['entityId']);
		self::assertSame('composer', $duplicates[0]['entityType']);
		self::assertSame(3, $duplicates[0]['count']);
	}

	public function testRemoveDuplicateSongEntityAssignments(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAllAssociative')->willReturn([
			[
				'song_id' => 10,
				'entity_id' => 25,
				'min_id' => 101,
			],
		]);

		$queryFunction = $this->createMock(IQueryFunction::class);

		$selectQb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('gt')->willReturn('gt');
		$expr->method('neq')->willReturn('neq');
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('createFunction')->willReturn($queryFunction);
		$selectQb->method('getColumnName')->willReturn('`se`.`id`');
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('selectAlias')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('innerJoin')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('groupBy')->willReturnSelf();
		$selectQb->method('having')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$delQb = $this->createMock(IQueryBuilder::class);
		$delQb->method('expr')->willReturn($expr);
		$delQb->method('delete')->willReturnSelf();
		$delQb->method('where')->willReturnSelf();
		$delQb->method('andWhere')->willReturnSelf();
		$delQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$delQb->expects(self::once())->method('executeStatement')->willReturn(2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($selectQb, $delQb);

		$mapper = new InternalEntityMapper($db);
		$removed = $mapper->removeDuplicateSongEntityAssignments(1, 10);

		self::assertSame(2, $removed);
	}

	public function testRemoveDuplicateSongEntityAssignmentsWithoutSongId(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAllAssociative')->willReturn([]);

		$queryFunction = $this->createMock(IQueryFunction::class);

		$selectQb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('gt')->willReturn('gt');
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('createFunction')->willReturn($queryFunction);
		$selectQb->method('getColumnName')->willReturn('`se`.`id`');
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('selectAlias')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('innerJoin')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('groupBy')->willReturnSelf();
		$selectQb->method('having')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($selectQb);

		$mapper = new InternalEntityMapper($db);
		$removed = $mapper->removeDuplicateSongEntityAssignments(1);

		self::assertSame(0, $removed);
	}

	public function testFindOrphanSongEntityAssignments(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			[
				'id' => 10,
				'song_id' => 50,
				'entity_id' => 20,
				'valid_song_id' => null,
				'valid_entity_id' => 20,
				'song_lib' => null,
				'ent_lib' => 1,
			],
			[
				'id' => 11,
				'song_id' => 51,
				'entity_id' => 21,
				'valid_song_id' => 51,
				'valid_entity_id' => null,
				'song_lib' => 1,
				'ent_lib' => null,
			],
			[
				'id' => 12,
				'song_id' => 52,
				'entity_id' => 22,
				'valid_song_id' => 52,
				'valid_entity_id' => 22,
				'song_lib' => 1,
				'ent_lib' => 2,
			],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$composite = $this->createMock(ICompositeExpression::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('neq')->willReturn('neq');
		$expr->method('andX')->willReturn($composite);
		$expr->method('orX')->willReturn($composite);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('leftJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalEntityMapper($db);
		$orphans = $mapper->findOrphanSongEntityAssignments(1);

		self::assertCount(3, $orphans);
		self::assertSame('Referenced song is deleted or missing', $orphans[0]['reason']);
		self::assertSame('Referenced entity is deleted or missing', $orphans[1]['reason']);
		self::assertSame('Song and entity belong to different libraries', $orphans[2]['reason']);
	}

	public function testRemoveOrphanSongEntityAssignments(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			[
				'id' => 10,
				'song_id' => 50,
				'entity_id' => 20,
				'valid_song_id' => null,
				'valid_entity_id' => 20,
				'song_lib' => null,
				'ent_lib' => 1,
			],
			false,
		);
		$selectResult->expects(self::once())->method('closeCursor')->willReturn(true);

		$composite = $this->createMock(ICompositeExpression::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('neq')->willReturn('neq');
		$expr->method('andX')->willReturn($composite);
		$expr->method('orX')->willReturn($composite);
		$expr->method('in')->willReturn('in');

		$selectQb = $this->createMock(IQueryBuilder::class);
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('leftJoin')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$delQb = $this->createMock(IQueryBuilder::class);
		$delQb->method('expr')->willReturn($expr);
		$delQb->method('delete')->willReturnSelf();
		$delQb->method('where')->willReturnSelf();
		$delQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$delQb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($selectQb, $delQb);

		$mapper = new InternalEntityMapper($db);
		$removed = $mapper->removeOrphanSongEntityAssignments(1);

		self::assertSame(1, $removed);
	}

	public function testFindDuplicateClientIds(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 1, 'type' => 'composer', 'client_id' => 10, 'name' => 'Bach'],
			['id' => 2, 'type' => 'composer', 'client_id' => 10, 'name' => 'Beethoven'],
			['id' => 3, 'type' => 'collection', 'client_id' => 5, 'name' => 'Hymns'],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('isNotNull')->willReturn('isNotNull');
		$expr->method('gt')->willReturn('gt');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalEntityMapper($db);
		$conflicts = $mapper->findDuplicateClientIds(1);

		self::assertCount(1, $conflicts);
		self::assertSame('composer', $conflicts[0]['type']);
		self::assertSame(10, $conflicts[0]['clientId']);
		self::assertSame([1, 2], $conflicts[0]['entityIds']);
	}

	public function testFixDuplicateClientIds(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 1, 'type' => 'composer', 'client_id' => 10, 'name' => 'Bach'],
			['id' => 2, 'type' => 'composer', 'client_id' => 10, 'name' => 'Beethoven'],
			false,
		);
		$selectResult->expects(self::once())->method('closeCursor')->willReturn(true);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('isNotNull')->willReturn('isNotNull');
		$expr->method('gt')->willReturn('gt');

		$selectQb = $this->createMock(IQueryBuilder::class);
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('orderBy')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$maxResult = $this->createMock(InternalQueryResult::class);
		$maxResult->method('fetchOne')->willReturn(15);

		$maxQb = $this->createMock(IQueryBuilder::class);
		$maxQb->method('expr')->willReturn($expr);
		$maxQb->method('createFunction')->willReturn($this->createMock(IQueryFunction::class));
		$maxQb->method('getColumnName')->willReturn('`client_id`');
		$maxQb->method('select')->willReturnSelf();
		$maxQb->method('from')->willReturnSelf();
		$maxQb->method('where')->willReturnSelf();
		$maxQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$maxQb->method('executeQuery')->willReturn($maxResult);

		$updateQb = $this->createMock(IQueryBuilder::class);
		$updateQb->method('expr')->willReturn($expr);
		$updateQb->method('update')->willReturnSelf();
		$updateQb->method('set')->willReturnSelf();
		$updateQb->method('where')->willReturnSelf();
		$updateQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$updateQb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($selectQb, $maxQb, $updateQb);

		$mapper = new InternalEntityMapper($db);
		$fixed = $mapper->fixDuplicateClientIds(1);

		self::assertSame(1, $fixed);
	}
}
