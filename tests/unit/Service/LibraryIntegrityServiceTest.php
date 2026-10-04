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
use OCA\MobileSheetsManager\Service\MobileSheetsPdfMetadata;
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

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);
		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(1, $issues);
		self::assertSame(IntegrityIssue::TYPE_MISSING_FILE, $issues[0]->type);
		self::assertSame('Test Song', $issues[0]->songTitle);
	}

	public function testScanOrphansIgnoresHashcodeFiles(): void {
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

		$targetFolder->method('getPath')->willReturn('/tmp');
		$songMapper->method('findSongs')->willReturn([]);
		$hashcodeNode = $this->createMock(\OCP\Files\Node::class);
		$hashcodeNode->method('getName')->willReturn('mobilesheets_hashcodes.txt');
		$hashcodeNode->method('getPath')->willReturn('/tmp/mobilesheets_hashcodes.txt');
		$targetFolder->method('getDirectoryListing')->willReturn([$hashcodeNode]);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);
		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(0, $issues);
	}

	public function testCheckIntegrityAllowsComposerTitleFlatPath(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createMock(SongMapper::class);
		$fileManager = $this->createMock(FileManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('user1');

		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/user1/files');
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$pathResolver = new MobileSheetsPathResolver($rootFolder);

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => '/tmp/dummy.db',
			'targetFolder' => $targetFolder,
		]);

		$song = new \OCA\MobileSheetsManager\Db\MobileSheetsSong(
			id: 1,
			title: 'Joshua',
			files: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsFile(10, '/remote.php/dav/files/user1/Noten/Georg Friedrich Händel - Joshua.pdf'),
			],
			composers: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsComposer(1, 'Georg Friedrich Händel'),
			],
		);
		$songMapper->method('findSongs')->willReturn([$song]);
		$pdf = $this->createMock(\OCP\Files\File::class);
		$pdf->method('getContent')->willReturn(MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			MobileSheetsPdfMetadata::fieldsFor($song),
		));
		$targetFolder->method('get')->with('Georg Friedrich Händel - Joshua.pdf')->willReturn($pdf);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);
		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(0, $issues);
	}

	public function testCheckIntegrityDetectsPdfMetadataMismatch(): void {
		$songMapper = $this->createMock(SongMapper::class);
		$fileManager = $this->createMock(FileManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('user1');

		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/user1/files');
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$pathResolver = new MobileSheetsPathResolver($rootFolder);
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => '/tmp/dummy.db',
			'targetFolder' => $targetFolder,
		]);

		$song = new \OCA\MobileSheetsManager\Db\MobileSheetsSong(
			id: 1,
			title: 'Joshua',
			files: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsFile(10, '/remote.php/dav/files/user1/Noten/Bach - Joshua.pdf'),
			],
			composers: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsComposer(1, 'Bach'),
			],
		);
		$songMapper->method('findSongs')->willReturn([$song]);

		$pdf = $this->createMock(\OCP\Files\File::class);
		$pdf->method('getContent')->willReturn(MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			['Title' => 'Wrong title', 'Author' => 'Bach', 'Composer' => 'Bach'],
		));
		$targetFolder->method('get')->with('Bach - Joshua.pdf')->willReturn($pdf);

		$service = new LibraryIntegrityService(
			$this->createMock(LibraryService::class),
			$songMapper,
			$fileManager,
			$rootFolder,
			$pathResolver,
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);
		$issues = $service->checkIntegrity(1, 'user1');
		$metadataIssues = array_values(array_filter(
			$issues,
			static fn (IntegrityIssue $issue): bool => $issue->type === IntegrityIssue::TYPE_PDF_METADATA_MISMATCH,
		));

		self::assertCount(1, $metadataIssues);
		self::assertSame(['Title'], $metadataIssues[0]->details['mismatchedFields']);
		self::assertSame(10, $metadataIssues[0]->fileId);
	}

	public function testCheckIntegrityDetectsIdenticalSongs(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createMock(SongMapper::class);
		$fileManager = $this->createMock(FileManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('user1');

		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/user1/files');
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$pathResolver = new MobileSheetsPathResolver($rootFolder);

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => '/tmp/dummy.db',
			'targetFolder' => $targetFolder,
		]);

		$song1 = new \OCA\MobileSheetsManager\Db\MobileSheetsSong(
			id: 1,
			title: 'Identical Song',
			files: [],
			composers: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsComposer(1, 'Bach'),
			],
		);
		$song2 = new \OCA\MobileSheetsManager\Db\MobileSheetsSong(
			id: 2,
			title: 'Identical Song',
			files: [],
			composers: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsComposer(1, 'Bach'),
			],
		);
		$songMapper->method('findSongs')->willReturn([$song1, $song2]);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);
		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(1, $issues);
		self::assertSame(IntegrityIssue::TYPE_IDENTICAL_SONG, $issues[0]->type);
		self::assertSame(IntegrityIssue::SEVERITY_INFO, $issues[0]->severity);
		self::assertTrue($issues[0]->fixable);
		self::assertEquals([1, 2], $issues[0]->details['songIds']);
	}

	public function testFixAllIssuesBatchFixesInvalidNamingRules(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createMock(SongMapper::class);
		$fileManager = $this->createMock(FileManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('user1');

		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/user1/files');
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$pathResolver = new MobileSheetsPathResolver($rootFolder);

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => '/tmp/dummy.db',
			'targetFolder' => $targetFolder,
		]);

		$song1 = new \OCA\MobileSheetsManager\Db\MobileSheetsSong(
			id: 10,
			title: 'Song 1',
			files: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsFile(100, '/remote.php/dav/files/user1/Noten/OldPath.pdf'),
			],
			composers: [
				new \OCA\MobileSheetsManager\Db\MobileSheetsComposer(1, 'Bach'),
			],
		);
		$songMapper->method('findSongs')->willReturn([$song1]);

		$fileManager->expects($this->once())
			->method('regenerateSongFilePaths')
			->with(10, $user, $targetFolder, '/tmp/dummy.db', $this->anything());

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);

		$result = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_INVALID_NAMING_RULE);

		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);
	}

	public function testCheckIntegrityPopulatesOrphanFileDetails(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createMock(SongMapper::class);
		$fileManager = $this->createMock(FileManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('user1');

		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/user1/files');
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$pathResolver = new MobileSheetsPathResolver($rootFolder);

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('OrphanScore.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/OrphanScore.pdf');
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => '/tmp/dummy.db',
			'targetFolder' => $targetFolder,
		]);
		$fileManager->method('pathOfExistingFile')->willReturn(
			new \OCA\MobileSheetsManager\Service\MobileSheetsPath(
				internal: '/user1/files/Noten/OrphanScore.pdf',
				webdav: '/remote.php/dav/files/user1/Noten/OrphanScore.pdf',
			)
		);

		$songMapper->method('findSongs')->willReturn([]);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);

		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(1, $issues);
		self::assertSame(IntegrityIssue::TYPE_ORPHAN_FILE, $issues[0]->type);
		self::assertSame('OrphanScore.pdf', $issues[0]->details['filename']);
		self::assertSame('/remote.php/dav/files/user1/Noten/OrphanScore.pdf', $issues[0]->details['webdavPath']);
		self::assertSame('Noten/OrphanScore.pdf', $issues[0]->details['userRelativePath']);
	}

	private static function minimalPdf(): string {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n";
		$xrefOffset = strlen("%PDF-1.4\n" . $objects);

		return "%PDF-1.4\n"
			. $objects
			. "xref\n0 4\n0000000000 65535 f \n"
			. "trailer << /Size 4 /Root 1 0 R >>\n"
			. "startxref\n{$xrefOffset}\n%%EOF\n";
	}
}
