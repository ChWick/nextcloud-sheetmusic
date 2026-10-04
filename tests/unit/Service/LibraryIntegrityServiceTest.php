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
		$songMapper->method('findSong')->with('/tmp/dummy.db', 1)->willReturn($song);

		$pdf = $this->createMock(\OCP\Files\File::class);
		$pdf->method('getContent')->willReturn(MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			['Title' => 'Wrong title', 'Author' => 'Bach', 'Composer' => 'Bach'],
		));
		$targetFolder->method('get')->with('Bach - Joshua.pdf')->willReturn($pdf);
		$fileManager->expects(self::once())
			->method('syncMetadataIntoFile')
			->with($song, $song->files[0], $user, '/tmp/dummy.db', self::anything())
			->willReturn(\OCA\MobileSheetsManager\Service\MobileSheetsFileLogEntry::CODE_METADATA_UPDATED);

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
		self::assertTrue($metadataIssues[0]->fixable);

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_PDF_METADATA_MISMATCH, 1, 10);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);
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

	public function testCheckIntegrityReportsAndFixesDuplicateEntityAssignments(): void {
		$songMapper = $this->createMock(SongMapper::class);
		$fileManager = $this->createMock(FileManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('user1');

		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/user1/files');
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		$fileManager->method('libraryContext')->with(1, $user)->willReturn([
			'databasePath' => '/tmp/dummy.db',
			'targetFolder' => $targetFolder,
		]);

		$song = new \OCA\MobileSheetsManager\Db\MobileSheetsSong(
			id: 7,
			title: 'Take Five',
			keys: [new \OCA\MobileSheetsManager\Db\MobileSheetsKey(3, 'C#m')],
			collections: [new \OCA\MobileSheetsManager\Db\MobileSheetsCollection(4, 'Jazz')],
		);
		$songMapper->method('findSongs')->with('/tmp/dummy.db')->willReturn([$song]);
		$songMapper->method('findDuplicateEntityAssignments')->with('/tmp/dummy.db')->willReturn([
			['songId' => 7, 'entityType' => 'keys', 'entityId' => 3, 'count' => 2],
			['songId' => 7, 'entityType' => 'collections', 'entityId' => 4, 'count' => 3],
		]);
		$songMapper->expects(self::exactly(2))
			->method('removeDuplicateEntityAssignments')
			->with('/tmp/dummy.db', 7)
			->willReturnOnConsecutiveCalls(3, 3);

		$service = new LibraryIntegrityService(
			$this->createMock(LibraryService::class),
			$songMapper,
			$fileManager,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$issues = $service->checkIntegrity(1, 'user1');
		self::assertCount(1, $issues);
		self::assertSame(IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT, $issues[0]->type);
		self::assertSame(7, $issues[0]->songId);
		self::assertTrue($issues[0]->fixable);
		self::assertSame(
			'Song "Take Five" has repeated entity assignments: 1 duplicate keys assignment(s), 2 duplicate collections assignment(s).',
			$issues[0]->message,
		);
		self::assertSame(2, count($issues[0]->details['duplicates']));

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT, 7);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);

		$batchResult = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT);
		self::assertTrue($batchResult['success']);
		self::assertSame(1, $batchResult['fixedCount']);
	}

	public function testCheckIntegrityReportsAndFixesDuplicateEntities(): void {
		$songMapper = $this->createMock(SongMapper::class);
		$fileManager = $this->createMock(FileManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('user1');

		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/user1/files');
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		$fileManager->method('libraryContext')->with(1, $user)->willReturn([
			'databasePath' => '/tmp/dummy.db',
			'targetFolder' => $targetFolder,
		]);

		$duplicates = [[
			'entityType' => 'keys',
			'name' => 'C',
			'keepId' => 2,
			'duplicateIds' => [9],
		]];
		$songMapper->method('findSongs')->with('/tmp/dummy.db')->willReturn([]);
		$songMapper->method('findDuplicateEntities')->with('/tmp/dummy.db')->willReturn($duplicates);
		$songMapper->expects(self::exactly(2))
			->method('mergeDuplicateEntities')
			->with('/tmp/dummy.db', 'keys', 2, [9])
			->willReturn(1);

		$service = new LibraryIntegrityService(
			$this->createMock(LibraryService::class),
			$songMapper,
			$fileManager,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$issues = $service->checkIntegrity(1, 'user1');
		self::assertCount(1, $issues);
		self::assertSame(IntegrityIssue::TYPE_DUPLICATE_ENTITY, $issues[0]->type);
		self::assertTrue($issues[0]->fixable);
		self::assertSame($duplicates[0], $issues[0]->details);

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY, entityType: 'keys', entityId: 2);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);

		$batchResult = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY);
		self::assertTrue($batchResult['success']);
		self::assertSame(1, $batchResult['fixedCount']);
	}

	public function testCheckIntegrityFindsDuplicateKeysFromSingularKeyTable(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'mobilesheetsmanager-integrity-keys-');
		self::assertNotFalse($databasePath);
		try {
			$database = new \PDO('sqlite:' . $databasePath);
			$database->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255))');
			$database->exec('CREATE TABLE "Key"(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER, LastModified INTEGER)');
			$database->exec('CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)');
			$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'First C'), (2, 'Second C')");
			$database->exec("INSERT INTO \"Key\" (Id, Name) VALUES (35, 'C'), (36, 'F'), (37, 'C'), (38, 'F')");
			$database->exec('INSERT INTO KeySongs (KeyId, SongId) VALUES (35, 1), (37, 2)');

			$rootFolder = $this->createMock(IRootFolder::class);
			$user = $this->createMock(\OCP\IUser::class);
			$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
			$userFolder->method('getOwner')->willReturn($user);
			$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

			$targetFolder = $this->createMock(Folder::class);
			$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
			$targetFolder->method('getDirectoryListing')->willReturn([]);
			$fileManager = $this->createMock(FileManager::class);
			$fileManager->method('libraryContext')->with(1, $user)->willReturn([
				'databasePath' => $databasePath,
				'targetFolder' => $targetFolder,
			]);

			$service = new LibraryIntegrityService(
				$this->createMock(LibraryService::class),
				new SongMapper($this->createMock(\Psr\Log\LoggerInterface::class)),
				$fileManager,
				$rootFolder,
				new MobileSheetsPathResolver($rootFolder),
				$this->createMock(\Psr\Log\LoggerInterface::class),
			);
			$issues = $service->checkIntegrity(1, 'user1');

			$duplicateKeyIssues = array_values(array_filter(
				$issues,
				static fn (IntegrityIssue $issue): bool => $issue->type === IntegrityIssue::TYPE_DUPLICATE_ENTITY,
			));
			self::assertCount(2, $duplicateKeyIssues);
			self::assertSame('C', $duplicateKeyIssues[0]->details['name']);
			self::assertSame(35, $duplicateKeyIssues[0]->details['keepId']);
			self::assertSame([37], $duplicateKeyIssues[0]->details['duplicateIds']);
			self::assertSame('F', $duplicateKeyIssues[1]->details['name']);
			self::assertSame(36, $duplicateKeyIssues[1]->details['keepId']);
			self::assertSame([38], $duplicateKeyIssues[1]->details['duplicateIds']);
		} finally {
			unlink($databasePath);
		}
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
