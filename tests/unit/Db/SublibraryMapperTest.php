<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSublibrary;
use OCA\SheetMusic\Db\SublibraryMapper;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class SublibraryMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private SublibraryMapper $mapper;
	private int $testLibId;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->mapper = new SublibraryMapper($this->dbConn);

		$lib = $this->createTestLibrary('/SublibTest/scores.db');
		$this->testLibId = (int)$lib->id;
	}

	protected function tearDown(): void {
		$this->cleanupLibraryData($this->testLibId);
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testInsertAndFindById(): void {
		$sub = new InternalSublibrary(
			libraryId: $this->testLibId,
			name: 'Organ Repertoire',
			slug: 'orgel',
			filters: ['instrument' => ['Orgel', 'Klavier']],
		);

		$saved = $this->mapper->insert($sub);
		self::assertNotNull($saved->id);
		self::assertGreaterThan(0, $saved->id);
		self::assertSame('Organ Repertoire', $saved->name);
		self::assertSame('orgel', $saved->slug);
		self::assertSame(['instrument' => ['Orgel', 'Klavier']], $saved->filters);

		$found = $this->mapper->findById((int)$saved->id);
		self::assertNotNull($found);
		self::assertSame($saved->id, $found->id);
		self::assertSame('Organ Repertoire', $found->name);
		self::assertSame('orgel', $found->slug);
		self::assertSame(['instrument' => ['Orgel', 'Klavier']], $found->filters);
	}

	public function testFindBySlug(): void {
		$sub = new InternalSublibrary(
			libraryId: $this->testLibId,
			name: 'Bach Scores',
			slug: 'bach',
			filters: ['composer' => ['J.S. Bach']],
		);
		$this->mapper->insert($sub);

		$found = $this->mapper->findBySlug($this->testLibId, 'bach');
		self::assertNotNull($found);
		self::assertSame('Bach Scores', $found->name);

		self::assertNull($this->mapper->findBySlug($this->testLibId, 'non-existent'));
	}

	public function testFindAllForLibrary(): void {
		$sub1 = new InternalSublibrary(
			libraryId: $this->testLibId,
			name: 'Organ',
			slug: 'orgel',
			filters: ['instrument' => ['Orgel']],
		);
		$sub2 = new InternalSublibrary(
			libraryId: $this->testLibId,
			name: 'Bass',
			slug: 'bass',
			filters: ['instrument' => ['Bass']],
		);
		$this->mapper->insert($sub1);
		$this->mapper->insert($sub2);

		$all = $this->mapper->findAllForLibrary($this->testLibId);
		self::assertCount(2, $all);
	}

	public function testUpdateAndDelete(): void {
		$sub = new InternalSublibrary(
			libraryId: $this->testLibId,
			name: 'Initial Name',
			slug: 'init',
			filters: ['instrument' => ['Orgel']],
		);
		$saved = $this->mapper->insert($sub);
		$subId = (int)$saved->id;

		$saved->name = 'Updated Name';
		$saved->slug = 'updated-slug';
		$saved->filters = ['instrument' => ['Orgel', 'Cembalo']];
		$updated = $this->mapper->update($saved);

		self::assertSame('Updated Name', $updated->name);
		self::assertSame('updated-slug', $updated->slug);
		self::assertSame(['instrument' => ['Orgel', 'Cembalo']], $updated->filters);

		$deleted = $this->mapper->delete($subId);
		self::assertTrue($deleted);
		self::assertNull($this->mapper->findById($subId));
	}
}
