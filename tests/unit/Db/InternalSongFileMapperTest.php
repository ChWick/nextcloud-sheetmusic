<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class InternalSongFileMapperTest extends TestCase {
	public function testFindByIdReturnsFile(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturn([
			'id' => 77,
			'uuid' => 'file-uuid-1',
			'song_id' => 10,
			'file_id' => 4567,
			'page_order' => '1-3',
			'page_count' => 3,
			'file_size' => 102400,
			'file_hash' => 12345678,
			'type' => 0,
			'client_id' => 1,
			'created_at' => 100,
			'updated_at' => 200,
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

		$mapper = new InternalSongFileMapper($db);
		$file = $mapper->findById(77);

		self::assertNotNull($file);
		self::assertSame(77, $file->id);
		self::assertSame(10, $file->songId);
		self::assertSame(4567, $file->fileId);
		self::assertSame('1-3', $file->pageOrder);
		self::assertSame(3, $file->pageCount);
		self::assertSame(102400, $file->fileSize);
	}

	public function testFindAllForSongReturnsFiles(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			[
				'id' => 1,
				'uuid' => 'u1',
				'song_id' => 10,
				'file_id' => 100,
			],
			[
				'id' => 2,
				'uuid' => 'u2',
				'song_id' => 10,
				'file_id' => 101,
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
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalSongFileMapper($db);
		$files = $mapper->findAllForSong(10);

		self::assertCount(2, $files);
		self::assertSame(100, $files[0]->fileId);
		self::assertSame(101, $files[1]->fileId);
	}

	public function testInsertSetsId(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('lastInsertId')->willReturn(123);

		$file = new InternalSongFile(
			id: null,
			uuid: 'sf-uuid',
			songId: 10,
			fileId: 999,
		);

		$mapper = new InternalSongFileMapper($db);
		$saved = $mapper->insert($file);

		self::assertSame(123, $saved->id);
	}

	public function testFindOrphanSongFiles(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 1, 'song_id' => 10, 'file_id' => 100, 'uuid' => 'f1'],
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
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalSongFileMapper($db);
		$orphans = $mapper->findOrphanSongFiles(1);

		self::assertCount(1, $orphans);
		self::assertSame(1, $orphans[0]['id']);
		self::assertSame(10, $orphans[0]['songId']);
	}

	public function testRemoveOrphanSongFiles(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 1, 'song_id' => 10, 'file_id' => 100, 'uuid' => 'f1'],
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
		$selectQb->method('orderBy')->willReturnSelf();
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

		$mapper = new InternalSongFileMapper($db);
		$removed = $mapper->removeOrphanSongFiles(1);

		self::assertSame(1, $removed);
	}
}
