<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSublibrary;
use OCA\SheetMusic\Db\SublibraryMapper;
use OCA\SheetMusic\Exception\SecurityException;
use OCA\SheetMusic\Service\LibraryShareService;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryShareServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LibraryShareService $shareService;
	private SublibraryMapper $sublibraryMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->sublibraryMapper = new SublibraryMapper($this->dbConn);
		$this->shareService = new LibraryShareService(
			$this->libraryMapper,
			$this->rootFolder,
			null,
			$this->userManager,
			null,
			null,
			$this->sublibraryMapper,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testOwnerHasAdminAccess(): void {
		$lib = $this->createTestLibrary('/Noten/shared_test.db', 'admin');

		self::assertSame(LibraryShareService::ACCESS_ADMIN, $this->shareService->getAccess('admin', $lib));
		self::assertTrue($this->shareService->canRead('admin', $lib));
		self::assertTrue($this->shareService->canWrite('admin', $lib));
		self::assertTrue($this->shareService->isOwner('admin', $lib));
	}

	public function testNonSharedUserHasNoAccess(): void {
		$lib = $this->createTestLibrary('/Noten/non_shared.db', 'admin');

		self::assertSame(LibraryShareService::ACCESS_NONE, $this->shareService->getAccess('other_user', $lib));
		self::assertFalse($this->shareService->canRead('other_user', $lib));
		self::assertFalse($this->shareService->canWrite('other_user', $lib));
		self::assertFalse($this->shareService->isOwner('other_user', $lib));
		self::assertNull($this->shareService->getAccessibleLibrary('other_user', (int)$lib->id));
	}

	public function testGetAccessibleLibrariesIncludesOwnedLibraries(): void {
		$lib = $this->createTestLibrary('/Noten/accessible_test.db', 'admin');

		$accessible = $this->shareService->getAccessibleLibraries('admin');
		$ids = array_map(fn (InternalLibrary $l) => $l->id, $accessible);
		self::assertContains($lib->id, $ids);

		$found = null;
		foreach ($accessible as $l) {
			if ($l->id === $lib->id) {
				$found = $l;
				break;
			}
		}

		self::assertNotNull($found);
		self::assertFalse($found->isShared);
		self::assertSame('admin', $found->ownerId);
		self::assertTrue($found->canWrite);
	}

	public function testNonOwnerCannotManageShares(): void {
		$lib = $this->createTestLibrary('/Noten/shares_guard.db', 'admin');

		$this->expectException(SecurityException::class);
		$this->shareService->getShares((int)$lib->id, 'intruder');
	}

	public function testShareCrudWithMockShareManager(): void {
		$lib = $this->createTestLibrary('/Noten/shares_crud.db', 'admin');

		$shareManager = $this->createMock(IShareManager::class);
		$urlGen = $this->createMock(IURLGenerator::class);
		$urlGen->method('getAbsoluteURL')->willReturnCallback(fn ($path) => 'https://cloud.test' . $path);

		$service = new LibraryShareService(
			$this->libraryMapper,
			$this->rootFolder,
			$shareManager,
			$this->userManager,
			$urlGen,
		);

		$mockShare = $this->createMock(IShare::class);
		$mockShare->method('getId')->willReturn('101');
		$mockShare->method('getFullId')->willReturn('ocinternal:101');
		$mockShare->method('getShareType')->willReturn(IShare::TYPE_USER);
		$mockShare->method('getSharedWith')->willReturn('bob');
		$mockShare->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$mockShare->method('getToken')->willReturn(null);
		$mockShare->method('getSharedBy')->willReturn('admin');

		$newShare = $this->createMock(IShare::class);
		$shareManager->method('newShare')->willReturn($newShare);
		$shareManager->method('createShare')->willReturn($mockShare);
		$shareManager->method('getSharesBy')->willReturnCallback(fn ($by, $type) => $type === IShare::TYPE_USER ? [$mockShare] : []);
		$shareManager->method('getShareById')->willReturn($mockShare);

		// Get shares
		$shares = $service->getShares((int)$lib->id, 'admin');
		self::assertCount(1, $shares);
		self::assertSame('101', $shares[0]['id']);
		self::assertSame('bob', $shares[0]['shareWith']);
		self::assertSame('read', $shares[0]['permission']);
		self::assertFalse($shares[0]['canWrite']);

		// Create share
		$created = $service->createShare((int)$lib->id, 'admin', IShare::TYPE_USER, 'bob', 'read');
		self::assertSame('bob', $created['shareWith']);

		// Delete share
		$shareManager->expects(self::once())->method('deleteShare')->with($mockShare);
		$service->deleteShare((int)$lib->id, 'admin', '101');
	}

	public function testFindLibraryByPublicToken(): void {
		$lib = $this->createTestLibrary('/Noten/public_link_test.db', 'admin');
		$folderId = $this->shareService->ensureLibraryFolderId($lib);
		self::assertNotNull($folderId);

		$shareManager = $this->createMock(IShareManager::class);
		$mockShare = $this->createMock(IShare::class);
		$mockFolder = $this->createMock(Folder::class);
		$mockFolder->method('getId')->willReturn($folderId);

		$mockShare->method('getNode')->willReturn($mockFolder);
		$mockShare->method('getTarget')->willReturn('/Noten');
		$mockShare->method('getSharedBy')->willReturn('admin');
		$mockShare->method('getToken')->willReturn('xyzSecretToken');
		$shareManager->method('getShareByToken')->with('xyzSecretToken')->willReturn($mockShare);

		$service = new LibraryShareService(
			$this->libraryMapper,
			$this->rootFolder,
			$shareManager,
			$this->userManager,
			null,
		);

		$result = $service->findLibraryByPublicToken('xyzSecretToken');
		self::assertNotNull($result);
		self::assertSame((int)$lib->id, (int)$result['library']->id);
		self::assertTrue($result['library']->isShared);
		self::assertSame('admin', $result['library']->ownerId);
		self::assertFalse($result['library']->canWrite);
		self::assertSame('xyzSecretToken', $result['token']);
	}

	public function testEnsureSublibraryFolderIdCreatesFolder(): void {
		$lib = $this->createTestLibrary('/Noten/sublib_folder_test.db', 'admin');
		$sub = new InternalSublibrary(
			libraryId: (int)$lib->id,
			name: 'Organ Works',
			slug: 'organ',
		);
		$createdSub = $this->sublibraryMapper->insert($sub);

		$folderId = $this->shareService->ensureSublibraryFolderId($createdSub, $lib);
		self::assertNotNull($folderId);
		self::assertGreaterThan(0, $folderId);

		$node = $this->shareService->getSublibraryFolderNode($createdSub, $lib);
		self::assertNotNull($node);
		self::assertSame($folderId, $node->getId());
	}

	public function testSublibraryShareCrud(): void {
		$lib = $this->createTestLibrary('/Noten/sublib_share_crud.db', 'admin');
		$sub = $this->sublibraryMapper->insert(new InternalSublibrary(
			libraryId: (int)$lib->id,
			name: 'Choir Pieces',
			slug: 'choir',
		));

		$shareManager = $this->createMock(IShareManager::class);
		$urlGen = $this->createMock(IURLGenerator::class);
		$urlGen->method('getAbsoluteURL')->willReturnCallback(fn ($path) => 'https://cloud.test' . $path);

		$service = new LibraryShareService(
			$this->libraryMapper,
			$this->rootFolder,
			$shareManager,
			$this->userManager,
			$urlGen,
			null,
			$this->sublibraryMapper,
		);

		$mockShare = $this->createMock(IShare::class);
		$mockShare->method('getId')->willReturn('201');
		$mockShare->method('getFullId')->willReturn('ocinternal:201');
		$mockShare->method('getShareType')->willReturn(IShare::TYPE_USER);
		$mockShare->method('getSharedWith')->willReturn('carol');
		$mockShare->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$mockShare->method('getToken')->willReturn(null);
		$mockShare->method('getSharedBy')->willReturn('admin');

		$newShare = $this->createMock(IShare::class);
		$shareManager->method('newShare')->willReturn($newShare);
		$shareManager->method('createShare')->willReturn($mockShare);
		$shareManager->method('getSharesBy')->willReturnCallback(fn ($by, $type) => $type === IShare::TYPE_USER ? [$mockShare] : []);
		$shareManager->method('getShareById')->willReturn($mockShare);

		// Get sublibrary shares
		$shares = $service->getSublibraryShares((int)$lib->id, (int)$sub->id, 'admin');
		self::assertCount(1, $shares);
		self::assertSame('201', $shares[0]['id']);
		self::assertSame('carol', $shares[0]['shareWith']);

		// Create sublibrary share
		$created = $service->createSublibraryShare((int)$lib->id, (int)$sub->id, 'admin', IShare::TYPE_USER, 'carol', 'read');
		self::assertSame('carol', $created['shareWith']);

		// Delete sublibrary share
		$shareManager->expects(self::once())->method('deleteShare')->with($mockShare);
		$service->deleteSublibraryShare((int)$lib->id, (int)$sub->id, 'admin', '201');
	}

	public function testFindSublibraryByPublicToken(): void {
		$lib = $this->createTestLibrary('/Noten/sublib_public_test.db', 'admin');
		$sub = $this->sublibraryMapper->insert(new InternalSublibrary(
			libraryId: (int)$lib->id,
			name: 'Solo Flute',
			slug: 'flute',
		));
		$folderId = $this->shareService->ensureSublibraryFolderId($sub, $lib);
		self::assertNotNull($folderId);

		$shareManager = $this->createMock(IShareManager::class);
		$mockShare = $this->createMock(IShare::class);
		$mockFolder = $this->createMock(Folder::class);
		$mockFolder->method('getId')->willReturn($folderId);

		$mockShare->method('getNode')->willReturn($mockFolder);
		$mockShare->method('getTarget')->willReturn('/Noten/_SubLibraries_/flute');
		$mockShare->method('getSharedBy')->willReturn('admin');
		$mockShare->method('getToken')->willReturn('subSecretToken');
		$shareManager->method('getShareByToken')->with('subSecretToken')->willReturn($mockShare);

		$service = new LibraryShareService(
			$this->libraryMapper,
			$this->rootFolder,
			$shareManager,
			$this->userManager,
			null,
			null,
			$this->sublibraryMapper,
		);

		$result = $service->findLibraryByPublicToken('subSecretToken');
		self::assertNotNull($result);
		self::assertSame(LibraryShareService::SUBLIBRARY_ID_OFFSET + (int)$sub->id, (int)$result['library']->id);
		self::assertSame((int)$sub->id, $result['library']->sublibraryId);
		self::assertTrue($result['library']->isShared);
		self::assertSame('subSecretToken', $result['token']);
	}
}
