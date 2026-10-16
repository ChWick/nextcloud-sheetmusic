<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class InternalSongAttributeMapperTest extends TestCase {
	public function testFindAllForSongReturnsMap(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['name' => 'custom', 'value' => 'Val1'],
			['name' => 'custom2', 'value' => 'Val2'],
			['name' => 'tuning', 'value' => 'Drop D'],
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
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalSongAttributeMapper($db);
		$attrs = $mapper->findAllForSong(10);

		self::assertSame([
			'custom' => 'Val1',
			'custom2' => 'Val2',
			'tuning' => 'Drop D',
		], $attrs);
	}

	public function testSetAttributeInsertsWhenNotExisting(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAssociative')->willReturn(false);

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

		$mapper = new InternalSongAttributeMapper($db);
		$mapper->setAttribute(10, 'tuning', 'Standard');
	}

	public function testRemoveAttributeDeletesRow(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalSongAttributeMapper($db);
		self::assertTrue($mapper->removeAttribute(10, 'tuning'));
	}

	public function testFindOrphanSongAttributes(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 101],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
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

		$mapper = new InternalSongAttributeMapper($db);
		$ids = $mapper->findOrphanSongAttributes(1);

		self::assertSame([101], $ids);
	}

	public function testRemoveOrphanSongAttributes(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 101],
			false,
		);
		$selectResult->expects(self::once())->method('closeCursor')->willReturn(true);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
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

		$delQb = $this->createMock(IQueryBuilder::class);
		$delQb->method('expr')->willReturn($expr);
		$delQb->method('delete')->willReturnSelf();
		$delQb->method('where')->willReturnSelf();
		$delQb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$delQb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($selectQb, $delQb);

		$mapper = new InternalSongAttributeMapper($db);
		$removed = $mapper->removeOrphanSongAttributes(1);

		self::assertSame(1, $removed);
	}
}
