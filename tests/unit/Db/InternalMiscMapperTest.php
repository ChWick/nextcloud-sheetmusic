<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalMiscMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class InternalMiscMapperTest extends TestCase {
	public function testGetMiscSchemasReturnsMap(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['table_name' => 'Setlists', 'create_sql' => 'CREATE TABLE Setlists (Id INT);'],
			['table_name' => 'Bookmarks', 'create_sql' => 'CREATE TABLE Bookmarks (Id INT);'],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalMiscMapper($db);
		$schemas = $mapper->getMiscSchemas(1, 'mobilesheets');

		self::assertSame([
			'Setlists' => 'CREATE TABLE Setlists (Id INT);',
			'Bookmarks' => 'CREATE TABLE Bookmarks (Id INT);',
		], $schemas);
	}

	public function testSaveMiscDataInsertsRow(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchOne')->willReturn(false);

		$qbSelect = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qbSelect->method('expr')->willReturn($expr);
		$qbSelect->method('select')->willReturnSelf();
		$qbSelect->method('from')->willReturnSelf();
		$qbSelect->method('where')->willReturnSelf();
		$qbSelect->method('andWhere')->willReturnSelf();
		$qbSelect->method('setMaxResults')->willReturnSelf();
		$qbSelect->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qbSelect->method('executeQuery')->willReturn($selectResult);

		$qbInsert = $this->createMock(IQueryBuilder::class);
		$qbInsert->method('insert')->willReturnSelf();
		$qbInsert->method('values')->willReturnSelf();
		$qbInsert->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qbInsert->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qbSelect, $qbInsert);

		$mapper = new InternalMiscMapper($db);
		$mapper->saveMiscData(1, 'mobilesheets', 'Setlists', '42', ['Id' => 42, 'Title' => 'Sunday Concert']);
	}

	public function testGetMiscDataForTableDecodesJson(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['data_json' => json_encode(['Id' => 1, 'Name' => 'Set 1'])],
			['data_json' => json_encode(['Id' => 2, 'Name' => 'Set 2'])],
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
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalMiscMapper($db);
		$data = $mapper->getMiscDataForTable(1, 'mobilesheets', 'Setlists');

		self::assertCount(2, $data);
		self::assertSame('Set 1', $data[0]['Name']);
		self::assertSame('Set 2', $data[1]['Name']);
	}

	public function testDeleteMiscDataForSongUpdatesDeletedAt(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->expects(self::once())->method('executeStatement')->willReturn(3);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalMiscMapper($db);
		$count = $mapper->deleteMiscDataForSong(1, 'mobilesheets', 'song-uuid-123', hardDelete: false);

		self::assertSame(3, $count);
	}

	public function testDeleteMiscDataForSongHardDeleteDeletesRows(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->expects(self::once())->method('executeStatement')->willReturn(2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalMiscMapper($db);
		$count = $mapper->deleteMiscDataForSong(1, 'mobilesheets', 'song-uuid-123', hardDelete: true);

		self::assertSame(2, $count);
	}

	public function testFindOrphanMiscData(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 77],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('isNotNull')->willReturn('isNotNull');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalMiscMapper($db);
		$ids = $mapper->findOrphanMiscData(1);

		self::assertSame([77], $ids);
	}

	public function testRemoveOrphanMiscData(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 77],
			false,
		);
		$selectResult->expects(self::once())->method('closeCursor')->willReturn(true);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('isNotNull')->willReturn('isNotNull');
		$expr->method('in')->willReturn('in');

		$selectQb = $this->createMock(IQueryBuilder::class);
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('innerJoin')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$updateQb = $this->createMock(IQueryBuilder::class);
		$updateQb->method('expr')->willReturn($expr);
		$updateQb->method('update')->willReturnSelf();
		$updateQb->method('set')->willReturnSelf();
		$updateQb->method('where')->willReturnSelf();
		$updateQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$updateQb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($selectQb, $updateQb);

		$mapper = new InternalMiscMapper($db);
		$removed = $mapper->removeOrphanMiscData(1);

		self::assertSame(1, $removed);
	}
}
