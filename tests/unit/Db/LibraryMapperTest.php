<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\LibraryMapper;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private string $testUser = 'lib_test_user';

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->cleanTestUsers();
	}

	protected function tearDown(): void {
		$this->cleanTestUsers();
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	private function cleanTestUsers(): void {
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_libraries WHERE user_id LIKE ?', ['lib_test_user%']);
		$this->dbConn->executeStatement('DELETE FROM *PREFIX*sheetmusic_libraries WHERE user_id = ?', ['other_user']);
	}

	public function testFindAllForUserReturnsTheLibrariesOfTheUser(): void {
		$this->libraryMapper->addForUser($this->testUser, '/Noten/b.db');
		$this->libraryMapper->addForUser($this->testUser, '/Noten/a.db');
		$this->libraryMapper->addForUser('other_user', '/Noten/other.db');

		$libraries = $this->libraryMapper->findAllForUser($this->testUser);

		self::assertCount(2, $libraries);
		self::assertSame('/Noten/a.db', $libraries[0]['path']);
		self::assertSame('/Noten/b.db', $libraries[1]['path']);
		self::assertTrue($libraries[0]['autoPaths']);
		self::assertTrue($libraries[0]['autoPdfMetadata']);
	}

	public function testFindAllForUserWithoutLibrariesReturnsAnEmptyList(): void {
		self::assertSame([], $this->libraryMapper->findAllForUser('non_existent_user'));
	}

	public function testFindByIdAndFindModelById(): void {
		$this->libraryMapper->addForUser($this->testUser, '/Noten/find_by_id.db');
		$libs = $this->libraryMapper->findAllForUser($this->testUser);
		$id = $libs[0]['id'];

		$row = $this->libraryMapper->findById($id);
		self::assertNotNull($row);
		self::assertSame($id, (int)$row['id']);
		self::assertSame('/Noten/find_by_id.db', $row['path']);
		self::assertSame($this->testUser, $row['user_id']);

		$model = $this->libraryMapper->findModelById($id);
		self::assertNotNull($model);
		self::assertSame($id, $model->id);
		self::assertSame('/Noten/find_by_id.db', $model->path);

		self::assertNull($this->libraryMapper->findById(999999));
		self::assertNull($this->libraryMapper->findModelById(999999));
	}

	public function testTouchUpdatesTimestamp(): void {
		$this->libraryMapper->addForUser($this->testUser, '/Noten/touch.db');
		$libs = $this->libraryMapper->findAllForUser($this->testUser);
		$id = $libs[0]['id'];

		$this->libraryMapper->touch($id, 1234567890);

		$row = $this->libraryMapper->findById($id);
		self::assertNotNull($row);
		self::assertSame(1234567890, (int)$row['updated_at']);
	}

	public function testAddForUserIgnoresDuplicatePath(): void {
		$this->libraryMapper->addForUser($this->testUser, '/Noten/duplicate.db');
		$this->libraryMapper->addForUser($this->testUser, '/Noten/duplicate.db');

		$libs = $this->libraryMapper->findAllForUser($this->testUser);
		self::assertCount(1, $libs);
	}

	public function testDeleteForUser(): void {
		$this->libraryMapper->addForUser($this->testUser, '/Noten/to_delete.db');
		$libs = $this->libraryMapper->findAllForUser($this->testUser);
		$id = $libs[0]['id'];

		self::assertTrue($this->libraryMapper->deleteForUser($this->testUser, $id));
		self::assertEmpty($this->libraryMapper->findAllForUser($this->testUser));

		// Deleting again or deleting non-existent returns false
		self::assertFalse($this->libraryMapper->deleteForUser($this->testUser, $id));
	}

	public function testFindForUser(): void {
		$this->libraryMapper->addForUser($this->testUser, '/Noten/find_user.db');
		$libs = $this->libraryMapper->findAllForUser($this->testUser);
		$id = $libs[0]['id'];

		$found = $this->libraryMapper->findForUser($this->testUser, $id);
		self::assertNotNull($found);
		self::assertSame($id, $found['id']);
		self::assertSame('/Noten/find_user.db', $found['path']);

		// Different user cannot find it
		self::assertNull($this->libraryMapper->findForUser('other_user', $id));
		self::assertNull($this->libraryMapper->findForUser($this->testUser, 999999));
	}

	public function testSetSettingsForUserAutoPathsOnly(): void {
		$this->libraryMapper->addForUser($this->testUser, '/Noten/settings.db');
		$libs = $this->libraryMapper->findAllForUser($this->testUser);
		$id = $libs[0]['id'];

		$this->libraryMapper->setSettingsForUser($this->testUser, $id, autoPaths: false);

		$found = $this->libraryMapper->findForUser($this->testUser, $id);
		self::assertNotNull($found);
		self::assertFalse($found['autoPaths']);
	}

	public function testSetSettingsForUser(): void {
		$this->libraryMapper->addForUser($this->testUser, '/Noten/settings2.db');
		$libs = $this->libraryMapper->findAllForUser($this->testUser);
		$id = $libs[0]['id'];

		$this->libraryMapper->setSettingsForUser($this->testUser, $id, autoPaths: false, autoPdfMetadata: false);

		$found = $this->libraryMapper->findForUser($this->testUser, $id);
		self::assertNotNull($found);
		self::assertFalse($found['autoPaths']);
		self::assertFalse($found['autoPdfMetadata']);
	}

	public function testAutoPathsOfHandlesDefaultAndNull(): void {
		self::assertTrue(LibraryMapper::autoPathsOf(['autoPaths' => true]));
		self::assertFalse(LibraryMapper::autoPathsOf(['autoPaths' => false]));
		self::assertTrue(LibraryMapper::autoPathsOf(['auto_paths' => 1]));
		self::assertFalse(LibraryMapper::autoPathsOf(['auto_paths' => 0]));
		self::assertTrue(LibraryMapper::autoPathsOf(['auto_paths' => null]));
		self::assertTrue(LibraryMapper::autoPathsOf([]));
	}

	public function testAutoPdfMetadataOfHandlesDefaultAndNull(): void {
		self::assertTrue(LibraryMapper::autoPdfMetadataOf(['autoPdfMetadata' => true]));
		self::assertFalse(LibraryMapper::autoPdfMetadataOf(['autoPdfMetadata' => false]));
		self::assertTrue(LibraryMapper::autoPdfMetadataOf(['auto_pdf_metadata' => 1]));
		self::assertFalse(LibraryMapper::autoPdfMetadataOf(['auto_pdf_metadata' => 0]));
		self::assertTrue(LibraryMapper::autoPdfMetadataOf(['auto_pdf_metadata' => null]));
		self::assertTrue(LibraryMapper::autoPdfMetadataOf([]));
	}
}
