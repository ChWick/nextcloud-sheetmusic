<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The result of the server versions this app runs on: the nextcloud/ocp stubs of
 * stable31 predate fetchAssociative(), which the server added to IResult later.
 * Declaring it again is compatible with both.
 */
interface QueryResult extends IResult {
	public function fetchAssociative(): array|false;
}

/**
 * Covers LibraryMapper against a mocked query builder.
 *
 * The database layer is mocked wholesale: what is asserted is which queries the
 * mapper runs, which values it binds and what it makes of the result rows -
 * including the string values real databases hand back for integer columns.
 */
final class LibraryMapperTest extends TestCase {
	/** @var list<array{value: mixed, type: mixed}> recorded createNamedParameter() calls */
	private array $parameters = [];

	/** How many rows the next executeStatement() reports as affected. */
	private int $affectedRows = 1;

	/** How many write statements were run. */
	private int $statements = 0;

	protected function setUp(): void {
		$this->parameters = [];
		$this->affectedRows = 1;
		$this->statements = 0;
	}

	public function testFindAllForUserReturnsTheLibrariesOfTheUser(): void {
		$result = $this->createMock(QueryResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['id' => '3', 'path' => '/Noten/b.db'],
			['id' => '7', 'path' => '/Noten/a.db'],
			false,
		);
		$result->expects(self::once())->method('closeCursor')->willReturn(true);

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($result)));

		self::assertSame(
			[
				['id' => 3, 'path' => '/Noten/b.db', 'autoPaths' => true],
				['id' => 7, 'path' => '/Noten/a.db', 'autoPaths' => true],
			],
			$mapper->findAllForUser('admin'),
		);
		self::assertContains(['value' => 'admin', 'type' => IQueryBuilder::PARAM_STR], $this->parameters);
	}

	public function testFindAllForUserWithoutLibrariesReturnsAnEmptyList(): void {
		$result = $this->createMock(QueryResult::class);
		$result->method('fetchAssociative')->willReturn(false);

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($result)));

		self::assertSame([], $mapper->findAllForUser('admin'));
	}

	public function testFindByIdReturnsTheRow(): void {
		$result = $this->createMock(QueryResult::class);
		$result->method('fetch')->willReturn(['id' => '3', 'user_id' => 'admin', 'path' => '/Noten/a.db']);

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($result)));

		self::assertSame(['id' => '3', 'user_id' => 'admin', 'path' => '/Noten/a.db'], $mapper->findById(3));
	}

	/**
	 * fetch() hands back false when the row is missing, which must come out as
	 * null - the false would otherwise violate the ?array return type.
	 */
	public function testFindByIdOfAnUnknownLibraryReturnsNull(): void {
		$result = $this->createMock(QueryResult::class);
		$result->method('fetch')->willReturn(false);

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($result)));

		self::assertNull($mapper->findById(999));
	}

	public function testAddForUserInsertsTheLibrary(): void {
		$result = $this->createMock(QueryResult::class);
		$result->method('fetchOne')->willReturn(false);

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($result)));
		$mapper->addForUser('admin', '/Noten/a.db');

		self::assertSame(1, $this->statements);
		self::assertContains(['value' => 'admin', 'type' => IQueryBuilder::PARAM_STR], $this->parameters);
		self::assertContains(['value' => '/Noten/a.db', 'type' => IQueryBuilder::PARAM_STR], $this->parameters);
		self::assertContains(['value' => true, 'type' => IQueryBuilder::PARAM_BOOL], $this->parameters);
	}

	public function testAddForUserIgnoresAPathThatIsAlreadyThere(): void {
		$result = $this->createMock(QueryResult::class);
		$result->method('fetchOne')->willReturn('/Noten/a.db');

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($result)));
		$mapper->addForUser('admin', '/Noten/a.db');

		self::assertSame(0, $this->statements, 'a duplicate may not be inserted');
	}

	public function testDeleteForUserRemovesTheEntry(): void {
		$this->affectedRows = 1;

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($this->createMock(QueryResult::class))));
		self::assertTrue($mapper->deleteForUser('admin', 7));
		self::assertSame(1, $this->statements);
		self::assertContains(['value' => 'admin', 'type' => IQueryBuilder::PARAM_STR], $this->parameters);
		self::assertContains(['value' => 7, 'type' => IQueryBuilder::PARAM_INT], $this->parameters);
	}

	public function testDeleteForUserOfAnUnknownLibraryReportsNothingRemoved(): void {
		$this->affectedRows = 0;

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($this->createMock(QueryResult::class))));
		self::assertFalse($mapper->deleteForUser('admin', 7));
	}

	public function testFindForUserReturnsTheRowWithTypedColumns(): void {
		$result = $this->createMock(QueryResult::class);
		$result->method('fetchAssociative')->willReturn(['id' => '7', 'path' => '/Noten/a.db', 'auto_paths' => '1']);

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($result)));

		self::assertSame(['id' => 7, 'path' => '/Noten/a.db', 'autoPaths' => true], $mapper->findForUser('admin', 7));
		self::assertContains(['value' => 'admin', 'type' => IQueryBuilder::PARAM_STR], $this->parameters);
		self::assertContains(['value' => 7, 'type' => IQueryBuilder::PARAM_INT], $this->parameters);
	}

	public function testFindForUserOfAnUnknownLibraryReturnsNull(): void {
		$result = $this->createMock(QueryResult::class);
		$result->method('fetchAssociative')->willReturn(false);

		$mapper = new LibraryMapper($this->connection($this->queryBuilder($result)));

		self::assertNull($mapper->findForUser('admin', 7));
	}

	public function testSetAutoPathsForUserUpdatesColumn(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		foreach (['update', 'set', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null) {
			$this->parameters[] = ['value' => $value, 'type' => $type];
			return $this->createMock(IParameter::class);
		});
		$qb->expects(self::once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new LibraryMapper($db);
		$mapper->setAutoPathsForUser('admin', 7, false);

		self::assertContains(['value' => false, 'type' => IQueryBuilder::PARAM_BOOL], $this->parameters);
		self::assertContains(['value' => 'admin', 'type' => IQueryBuilder::PARAM_STR], $this->parameters);
		self::assertContains(['value' => 7, 'type' => IQueryBuilder::PARAM_INT], $this->parameters);
	}

	public function testAutoPathsOfHandlesDefaultAndNull(): void {
		self::assertTrue(LibraryMapper::autoPathsOf(['autoPaths' => true]));
		self::assertFalse(LibraryMapper::autoPathsOf(['autoPaths' => false]));
		self::assertTrue(LibraryMapper::autoPathsOf(['auto_paths' => 1]));
		self::assertFalse(LibraryMapper::autoPathsOf(['auto_paths' => 0]));
		self::assertTrue(LibraryMapper::autoPathsOf(['auto_paths' => null]));
		self::assertTrue(LibraryMapper::autoPathsOf([]));
	}

	/**
	 * A query builder whose fluent API returns itself, which answers
	 * executeQuery() with the given result and records bound parameters and
	 * write statements.
	 */
	private function queryBuilder(IResult $result): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'insert', 'setValue', 'delete'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null) {
			$this->parameters[] = ['value' => $value, 'type' => $type];

			return $this->createMock(IParameter::class);
		});
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturnCallback(function (): int {
			$this->statements++;

			return $this->affectedRows;
		});

		return $qb;
	}

	private function connection(IQueryBuilder $qb): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}
}
