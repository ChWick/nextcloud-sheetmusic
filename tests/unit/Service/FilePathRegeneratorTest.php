<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Tests\Unit\Service;

use OCA\SheetMusic\Service\FilePathRegenerator;
use OCA\SheetMusic\Service\SyncException;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPath;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FilePathRegeneratorTest extends TestCase {
	private IRootFolder $rootFolder;
	private MobileSheetsPathResolver $pathResolver;
	private FilePathRegenerator $regenerator;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$this->regenerator = new FilePathRegenerator(
			$this->rootFolder,
			$this->pathResolver,
			new NullLogger(),
		);
	}

	public function testUniqueRelativePathReturnsOriginalWhenNotExists(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);

		$result = $this->regenerator->uniqueRelativePath($folder, 'Noten/My Song.pdf');
		self::assertSame('Noten/My Song.pdf', $result);
	}

	public function testUniqueRelativePathIncrementsCounterWhenTaken(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnMap([
			['Noten/My Song.pdf', true],
			['Noten/My Song (2).pdf', false],
		]);

		$result = $this->regenerator->uniqueRelativePath($folder, 'Noten/My Song.pdf');
		self::assertSame('Noten/My Song (2).pdf', $result);
	}

	public function testUniqueRelativePathRespectsKeepRelative(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);

		$result = $this->regenerator->uniqueRelativePath($folder, 'Noten/My Song.pdf', 'Noten/My Song.pdf');
		self::assertSame('Noten/My Song.pdf', $result);
	}

	public function testEnsureParentFolderCreatesMissingSegments(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnMap([
			['Noten', false],
			['Noten/Jazz', false],
		]);

		$created = [];
		$folder->method('newFolder')->willReturnCallback(function (string $path) use (&$created, $folder) {
			$created[] = $path;
			return $folder;
		});

		$result = $this->regenerator->ensureParentFolder($folder, 'Noten/Jazz/Miles.pdf');
		self::assertSame(['Noten', 'Noten/Jazz'], $result);
		self::assertSame(['Noten', 'Noten/Jazz'], $created);
	}

	public function testEnsureParentFolderReturnsEmptyForRoot(): void {
		$folder = $this->createMock(Folder::class);
		$result = $this->regenerator->ensureParentFolder($folder, 'rootfile.pdf');
		self::assertSame([], $result);
	}

	public function testNodeIdOfStoredFileReturnsNullWhenCannotResolve(): void {
		$user = $this->createMock(IUser::class);
		$this->pathResolver->method('fromStored')->willReturn(null);

		$id = $this->regenerator->nodeIdOfStoredFile('/some/invalid/path.pdf', $user);
		self::assertNull($id);
	}

	public function testNodeIdOfStoredFileReturnsIdWhenNodeExists(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$msPath = new MobileSheetsPath('/admin/files/Noten/song.pdf', '/remote.php/dav/files/admin/Noten/song.pdf');
		$this->pathResolver->method('fromStored')->willReturn($msPath);
		$this->pathResolver->method('relativeToUserFolder')->willReturn('Noten/song.pdf');

		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(42);

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('get')->with('Noten/song.pdf')->willReturn($fileNode);
		$this->rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$id = $this->regenerator->nodeIdOfStoredFile('/remote.php/dav/files/admin/Noten/song.pdf', $user);
		self::assertSame(42, $id);
	}

	public function testRegenerateFilePathThrowsWhenStoredOutsideLibrary(): void {
		$user = $this->createMock(IUser::class);
		$this->pathResolver->method('fromStored')->willReturn(null);
		$this->pathResolver->method('webdavRoot')->willReturn('/remote.php/dav/files/admin');

		$song = new MobileSheetsSong(1, 'Test Song');
		$file = new MobileSheetsFile(1, '/invalid/outside.pdf');
		$targetFolder = $this->createMock(Folder::class);

		$this->expectException(SyncException::class);
		$this->regenerator->regenerateFilePath($song, $file, $user, $targetFolder, '/db.db');
	}
}
