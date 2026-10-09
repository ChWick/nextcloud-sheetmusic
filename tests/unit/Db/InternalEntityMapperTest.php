<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
}
