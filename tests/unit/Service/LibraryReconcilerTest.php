<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\LibraryReconciler;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LibraryReconcilerTest extends TestCase {
	public function testReconcileIndexesNewFilesAndPrunesMissing(): void {
		$songMapper = $this->createMock(InternalSongMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		// File 1 is physical and untracked -> should be indexed
		$physicalFile1 = $this->createMock(File::class);
		$physicalFile1->method('getId')->willReturn(101);
		$physicalFile1->method('getName')->willReturn('001 New Score.pdf');
		$physicalFile1->method('getSize')->willReturn(50000);
		$physicalFile1->method('getContent')->willReturn('%PDF-1.4 mock content');

		// Existing tracked file: fileId 999 is NOT in physical folder -> should be pruned
		$staleTrackedFile = new InternalSongFile(
			id: 55,
			uuid: 'stale-uuid',
			songId: 10,
			fileId: 999,
		);

		$songFileMapper->method('findAllForLibrary')->with(1, false)->willReturn([$staleTrackedFile]);

		$libFolder = $this->createMock(Folder::class);
		$libFolder->method('getDirectoryListing')->willReturn([$physicalFile1]);

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('nodeExists')->with('Noten')->willReturn(true);
		$userFolder->method('get')->with('Noten')->willReturn($libFolder);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		// Song creation expectation
		$songMapper->method('findByTitle')->with(1, 'New Score')->willReturn(null);
		$insertedSong = null;
		$songMapper->method('insert')->willReturnCallback(function (InternalSong $s) use (&$insertedSong) {
			$s->id = 77;
			$insertedSong = $s;
			return $s;
		});

		$insertedSongFile = null;
		$songFileMapper->method('insert')->willReturnCallback(function (InternalSongFile $sf) use (&$insertedSongFile) {
			$insertedSongFile = $sf;
			return $sf;
		});

		$deletedFileId = null;
		$songFileMapper->method('delete')->willReturnCallback(function ($id, $hardDelete) use (&$deletedFileId) {
			$deletedFileId = $id;
			self::assertFalse($hardDelete);
			return true;
		});

		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$reconciler = new LibraryReconciler(
			$songMapper,
			$songFileMapper,
			$libraryMapper,
			$rootFolder,
			$userManager,
			$logger,
		);

		$result = $reconciler->reconcile($library);

		self::assertSame(1, $result['indexed']);
		self::assertSame(1, $result['pruned']);

		self::assertNotNull($insertedSong);
		self::assertSame('New Score', $insertedSong->title);

		self::assertNotNull($insertedSongFile);
		self::assertSame(77, $insertedSongFile->songId);
		self::assertSame(101, $insertedSongFile->fileId);

		self::assertSame(55, $deletedFileId);
	}

	public function testExtractTitleFromFilenameRemovesNumericPrefixes(): void {
		$songMapper = $this->createMock(InternalSongMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$reconciler = new LibraryReconciler($songMapper, $songFileMapper, $libraryMapper, $rootFolder, $userManager, $logger);

		self::assertSame('Jazz - Take Five', $reconciler->extractTitleFromFilename('001 Jazz - Take Five.pdf'));
		self::assertSame('Moonlight Sonata', $reconciler->extractTitleFromFilename('Moonlight_Sonata.pdf'));
		self::assertSame('Concerto in G', $reconciler->extractTitleFromFilename('Concerto in G.pdf'));
	}

	public function testIsIgnoredFilename(): void {
		$songMapper = $this->createMock(InternalSongMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$reconciler = new LibraryReconciler($songMapper, $songFileMapper, $libraryMapper, $rootFolder, $userManager, $logger);

		self::assertTrue($reconciler->isIgnoredFilename('mobilesheets.db'));
		self::assertTrue($reconciler->isIgnoredFilename('mobilesheets.db-wal'));
		self::assertTrue($reconciler->isIgnoredFilename('my_library.db'));
		self::assertTrue($reconciler->isIgnoredFilename('mobilesheets_hashcodes.txt'));
		self::assertFalse($reconciler->isIgnoredFilename('Sonata.pdf'));
	}
}
