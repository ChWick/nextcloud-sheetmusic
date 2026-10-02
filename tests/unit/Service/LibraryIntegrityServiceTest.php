<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\IntegrityIssue;
use OCA\MobileSheetsManager\Service\LibraryIntegrityService;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCA\MobileSheetsManager\Service\MobileSheetsPathResolver;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

final class LibraryIntegrityServiceTest extends TestCase {
	public function testCheckIntegrityDetectsMissingFile(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createMock(SongMapper::class);
		$fileManager = $this->createMock(FileManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$pathResolver = new MobileSheetsPathResolver($rootFolder);

		$targetFolder = $this->createMock(Folder::class);
		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$user = $this->createMock(\OCP\IUser::class);
		$userFolder->method('getOwner')->willReturn($user);
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => '/tmp/dummy.db',
			'targetFolder' => $targetFolder,
		]);

		$song = new \OCA\MobileSheetsManager\Db\MobileSheetsSong(
			id: 1,
			title: 'Test Song',
			files: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsFile(10, '/remote.php/dav/files/user1/Noten/missing.pdf'),
			],
		);
		$songMapper->method('findSongs')->willReturn([$song]);
		$targetFolder->method('nodeExists')->willReturn(false);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver);
		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(1, $issues);
		self::assertSame(IntegrityIssue::TYPE_MISSING_FILE, $issues[0]->type);
		self::assertSame('Test Song', $issues[0]->songTitle);
	}
}
