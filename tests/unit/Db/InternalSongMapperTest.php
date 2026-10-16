<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class InternalSongMapperTest extends TestCase {
	public function testFindByIdReturnsSong(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturn([
			'id' => 10,
			'uuid' => 'uuid-123',
			'library_id' => 1,
			'title' => 'Moonlight Sonata',
			'sort_title' => 'Moonlight Sonata',
			'rating' => 5,
			'difficulty' => 3,
			'duration' => 360,
			'keywords' => 'piano, beethoven',
			'notes' => 'Movement 1',
			'opus' => 'Op. 27 No. 2',
			'catalog_number' => 'WoO 47',
			'song_number' => '14',
			'client_id' => 42,
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

		$mapper = new InternalSongMapper($db);
		$song = $mapper->findById(10);

		self::assertNotNull($song);
		self::assertSame(10, $song->id);
		self::assertSame('uuid-123', $song->uuid);
		self::assertSame(1, $song->libraryId);
		self::assertSame('Moonlight Sonata', $song->title);
		self::assertSame('Op. 27 No. 2', $song->opus);
		self::assertSame('WoO 47', $song->catalogNumber);
		self::assertSame('14', $song->songNumber);
		self::assertSame(42, $song->clientId);
		self::assertSame(5, $song->rating);
	}

	public function testFindByIdReturnsNullWhenNotFound(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturn(false);

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

		$mapper = new InternalSongMapper($db);
		self::assertNull($mapper->findById(999));
	}

	public function testFindAllForLibraryReturnsSongs(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			[
				'id' => 1,
				'uuid' => 'uuid-1',
				'library_id' => 1,
				'title' => 'Song A',
				'created_at' => 100,
				'updated_at' => 100,
			],
			[
				'id' => 2,
				'uuid' => 'uuid-2',
				'library_id' => 1,
				'title' => 'Song B',
				'created_at' => 100,
				'updated_at' => 100,
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

		$mapper = new InternalSongMapper($db);
		$songs = $mapper->findAllForLibrary(1);

		self::assertCount(2, $songs);
		self::assertSame('Song A', $songs[0]->title);
		self::assertSame('Song B', $songs[1]->title);
	}

	public function testInsertSetsIdAndTimestamps(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('lastInsertId')->willReturn(55);

		$song = new InternalSong(
			id: null,
			uuid: 'new-uuid',
			libraryId: 1,
			title: 'New Piece',
			opus: 'Op. 1',
		);

		$mapper = new InternalSongMapper($db);
		$saved = $mapper->insert($song);

		self::assertSame(55, $saved->id);
		self::assertGreaterThan(0, $saved->createdAt);
		self::assertGreaterThan(0, $saved->updatedAt);
	}

	public function testDeleteAndRestore(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalSongMapper($db);
		self::assertTrue($mapper->delete(10));
		self::assertTrue($mapper->restore(10));
	}

	public function testFindDuplicateClientIds(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 1, 'client_id' => 50, 'title' => 'Song A'],
			['id' => 2, 'client_id' => 50, 'title' => 'Song B'],
			['id' => 3, 'client_id' => 60, 'title' => 'Song C'],
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

		$mapper = new InternalSongMapper($db);
		$conflicts = $mapper->findDuplicateClientIds(1);

		self::assertCount(1, $conflicts);
		self::assertSame(50, $conflicts[0]['clientId']);
		self::assertSame([1, 2], $conflicts[0]['songIds']);
	}

	public function testFixDuplicateClientIds(): void {
		$selectResult = $this->createMock(InternalQueryResult::class);
		$selectResult->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 1, 'client_id' => 50, 'title' => 'Song A'],
			['id' => 2, 'client_id' => 50, 'title' => 'Song B'],
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
		$maxResult->method('fetchOne')->willReturn(55);

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

		$mapper = new InternalSongMapper($db);
		$fixed = $mapper->fixDuplicateClientIds(1);

		self::assertSame(1, $fixed);
	}

	public function testFindSongsWithoutFiles(): void {
		$result = $this->createMock(InternalQueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => 99, 'title' => 'Empty Song', 'uuid' => 'u99'],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$composite = $this->createMock(ICompositeExpression::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('andX')->willReturn($composite);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('leftJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new InternalSongMapper($db);
		$songs = $mapper->findSongsWithoutFiles(1);

		self::assertCount(1, $songs);
		self::assertSame(99, $songs[0]['id']);
		self::assertSame('Empty Song', $songs[0]['title']);
	}
}
