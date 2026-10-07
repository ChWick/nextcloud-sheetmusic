<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\IntegrityIssue;
use OCA\MobileSheetsManager\Service\LibraryIntegrityService;
use OCA\MobileSheetsManager\Service\MobileSheetsPathResolver;
use OCA\MobileSheetsManager\Service\MobileSheetsPdfMetadata;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TestSupport\TestUtils;

final class LibraryIntegrityServiceTest extends TestCase {
	use TestUtils;

	public function testCheckIntegrityDetectsMissingFile(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Test Song')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/missing.pdf')");
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(false);
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

		try {
			$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
			$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);
			$issues = $service->checkIntegrity(1, 'user1');

			self::assertCount(1, $issues);
			self::assertSame(IntegrityIssue::TYPE_MISSING_FILE, $issues[0]->type);
			self::assertSame('Test Song', $issues[0]->songTitle);
		} finally {
			unlink($databasePath);
		}
	}

	public function testScanOrphansIgnoresHashcodeFiles(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/tmp');
		$hashcodeNode = $this->createMock(\OCP\Files\Node::class);
		$hashcodeNode->method('getName')->willReturn('mobilesheets_hashcodes.txt');
		$hashcodeNode->method('getPath')->willReturn('/tmp/mobilesheets_hashcodes.txt');
		$targetFolder->method('getDirectoryListing')->willReturn([$hashcodeNode]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);
		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(0, $issues);
		unlink($fixture['path']);
	}

	public function testCheckIntegrityAllowsComposerTitleFlatPath(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Joshua')");
		$fixture['connection']->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/Georg Friedrich Händel - Joshua.pdf')");
		$fixture['connection']->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Georg Friedrich Händel')");
		$fixture['connection']->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 1)');
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$song = $songMapper->findSongs($fixture['path'])[0];
		$pdf = $this->createMock(\OCP\Files\File::class);
		$pdf->method('getContent')->willReturn(MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			MobileSheetsPdfMetadata::fieldsFor($song),
		));
		$targetFolder->method('get')->with('Georg Friedrich Händel - Joshua.pdf')->willReturn($pdf);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);
		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(0, $issues);
		unlink($fixture['path']);
	}

	public function testCheckIntegrityDetectsPdfMetadataMismatch(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Joshua')");
		$fixture['connection']->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/Bach - Joshua.pdf')");
		$fixture['connection']->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Bach')");
		$fixture['connection']->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 1)');
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$song = $songMapper->findSongs($fixture['path'])[0];
		$pdf = $this->createMock(\OCP\Files\File::class);
		$pdfContent = MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			['Title' => 'Wrong title', 'Author' => 'Bach', 'Composer' => 'Bach'],
		);
		$pdf->method('getContent')->willReturnCallback(static fn (): string => $pdfContent);
		$pdf->method('putContent')->willReturnCallback(static function (string $content) use (&$pdfContent): int {
			$pdfContent = $content;
			return strlen($content);
		});
		$pdf->method('getMTime')->willReturn(1);
		$targetFolder->method('get')->with('Bach - Joshua.pdf')->willReturn($pdf);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager(
			$fixture['path'],
			$targetFolder,
			['Noten/Bach - Joshua.pdf' => $pdf],
		);

		$service = new LibraryIntegrityService(
			$this->createLibraryService(),
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
		self::assertSame([], MobileSheetsPdfMetadata::mismatchedFields($pdfContent, $song));
		self::assertGreaterThan(0, (int)$fixture['connection']->query('SELECT FileSize FROM Files WHERE Id = 10')->fetchColumn());
		unlink($fixture['path']);
	}

	public function testCheckIntegritySkipsPdfMetadataWhenDisabled(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Joshua')");
		$fixture['connection']->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/Bach - Joshua.pdf')");
		$fixture['connection']->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Bach')");
		$fixture['connection']->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 1)');
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$song = $songMapper->findSongs($fixture['path'])[0];
		$pdf = $this->createMock(\OCP\Files\File::class);
		$pdfContent = MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			['Title' => 'Wrong title', 'Author' => 'Bach', 'Composer' => 'Bach'],
		);
		$pdf->method('getContent')->willReturnCallback(static fn (): string => $pdfContent);
		$pdf->method('getMTime')->willReturn(1);
		$targetFolder->method('get')->with('Bach - Joshua.pdf')->willReturn($pdf);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager(
			$fixture['path'],
			$targetFolder,
			['Noten/Bach - Joshua.pdf' => $pdf],
		);

		$libraryService = $this->createMock(\OCA\MobileSheetsManager\Service\LibraryService::class);
		$libraryService->method('isAutoPathsEnabled')->willReturn(true);
		$libraryService->method('isAutoPdfMetadataEnabled')->willReturn(false);

		$service = new LibraryIntegrityService(
			$libraryService,
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

		self::assertCount(0, $metadataIssues);
		unlink($fixture['path']);
	}

	public function testCheckIntegrityDetectsIdenticalSongs(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Identical Song'), (2, 'Identical Song')");
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Bach')");
		$database->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 1), (1, 2)');
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

		try {
			$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
			$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);
			$issues = $service->checkIntegrity(1, 'user1');

			self::assertCount(1, $issues);
			self::assertSame(IntegrityIssue::TYPE_IDENTICAL_SONG, $issues[0]->type);
			self::assertSame(IntegrityIssue::SEVERITY_INFO, $issues[0]->severity);
			self::assertTrue($issues[0]->fixable);
			self::assertEquals([1, 2], $issues[0]->details['songIds']);
		} finally {
			unlink($databasePath);
		}
	}

	public function testCheckIntegrityReportsAndFixesDuplicateEntityAssignments(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		try {
			$database->exec("INSERT INTO Songs (Id, Title) VALUES (7, 'Take Five'), (8, 'Blue Rondo')");
			$database->exec("INSERT INTO Keys (Id, Name) VALUES (3, 'C#m')");
			$database->exec("INSERT INTO Collections (Id, Name) VALUES (4, 'Jazz')");
			$database->exec('INSERT INTO KeySongs (KeyId, SongId) VALUES (3, 7), (3, 7), (3, 8), (3, 8)');
			$database->exec('INSERT INTO CollectionSong (CollectionId, SongId) VALUES (4, 7), (4, 7), (4, 7)');

			$targetFolder = $this->createMock(Folder::class);
			$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
			$targetFolder->method('getDirectoryListing')->willReturn([]);
			[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

			$service = new LibraryIntegrityService(
				$this->createLibraryService(),
				$this->createSongMapper(),
				$fileManager,
				$rootFolder,
				$pathResolver,
				$this->createMock(\Psr\Log\LoggerInterface::class),
			);

			$issues = $service->checkIntegrity(1, 'user1');
			self::assertCount(2, $issues);
			self::assertSame(IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT, $issues[0]->type);
			self::assertSame(7, $issues[0]->songId);
			self::assertSame('Take Five', $issues[0]->songTitle);
			self::assertSame(
				['collections' => 3, 'keys' => 2],
				array_column($issues[0]->details['duplicates'], 'count', 'entityType'),
			);
			self::assertSame(
				'Song "Take Five" has repeated entity assignments: 2 duplicate collections assignment(s), 1 duplicate keys assignment(s).',
				$issues[0]->message,
			);
			self::assertSame(2, count($issues[0]->details['duplicates']));

			$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT, 7);
			self::assertTrue($result['success']);
			self::assertSame(1, $result['fixedCount']);
			self::assertSame(1, (int)$database->query('SELECT COUNT(*) FROM KeySongs WHERE KeyId = 3 AND SongId = 7')->fetchColumn());
			self::assertSame(1, (int)$database->query('SELECT COUNT(*) FROM CollectionSong WHERE CollectionId = 4 AND SongId = 7')->fetchColumn());

			$remainingIssues = $service->checkIntegrity(1, 'user1');
			self::assertCount(1, $remainingIssues);
			self::assertSame(8, $remainingIssues[0]->songId);

			$batchResult = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT);
			self::assertTrue($batchResult['success']);
			self::assertSame(1, $batchResult['fixedCount']);
			self::assertSame(1, (int)$database->query('SELECT COUNT(*) FROM KeySongs WHERE KeyId = 3 AND SongId = 8')->fetchColumn());
			self::assertSame([], $service->checkIntegrity(1, 'user1'));
		} finally {
			unlink($databasePath);
		}
	}

	public function testCheckIntegrityReportsAndFixesDuplicateEntities(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (7, 'Take Five'), (8, 'Blue Rondo')");
		$database->exec("INSERT INTO Keys (Id, Name) VALUES (2, 'C'), (9, ' c ')");
		$database->exec('INSERT INTO KeySongs (KeyId, SongId) VALUES (2, 7), (9, 7), (9, 8)');
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (4, 'Bach'), (8, ' bach ')");
		$database->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (8, 7), (8, 8)');
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

		try {
			$service = new LibraryIntegrityService(
				$this->createLibraryService(),
				$songMapper,
				$fileManager,
				$rootFolder,
				$pathResolver,
				$this->createMock(\Psr\Log\LoggerInterface::class),
			);

			$issues = $service->checkIntegrity(1, 'user1');
			self::assertCount(2, $issues);
			self::assertSame(IntegrityIssue::TYPE_DUPLICATE_ENTITY, $issues[0]->type);
			self::assertTrue($issues[0]->fixable);
			self::assertSame([
				'entityType' => 'composers',
				'name' => 'Bach',
				'keepId' => 4,
				'duplicateIds' => [8],
			], $issues[0]->details);
			self::assertSame([
				'entityType' => 'keys',
				'name' => 'C',
				'keepId' => 2,
				'duplicateIds' => [9],
			], $issues[1]->details);

			$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY, entityType: 'keys', entityId: 2);
			self::assertTrue($result['success']);
			self::assertSame(1, $result['fixedCount']);
			self::assertSame(2, (int)$database->query('SELECT COUNT(*) FROM KeySongs WHERE KeyId = 2')->fetchColumn());
			self::assertSame(0, (int)$database->query('SELECT COUNT(*) FROM Keys WHERE Id = 9')->fetchColumn());

			$batchResult = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY);
			self::assertTrue($batchResult['success']);
			self::assertSame(1, $batchResult['fixedCount']);
			self::assertSame(2, (int)$database->query('SELECT COUNT(*) FROM ComposerSongs WHERE ComposerId = 4')->fetchColumn());
			self::assertSame(0, (int)$database->query('SELECT COUNT(*) FROM Composer WHERE Id = 8')->fetchColumn());
			self::assertSame([], $service->checkIntegrity(1, 'user1'));
		} finally {
			unlink($databasePath);
		}
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

			$targetFolder = $this->createMock(Folder::class);
			$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
			$targetFolder->method('getDirectoryListing')->willReturn([]);
			[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

			$service = new LibraryIntegrityService(
				$this->createLibraryService(),
				$this->createSongMapper(),
				$fileManager,
				$rootFolder,
				$pathResolver,
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
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (10, 'Song 1')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (100, 10, '/remote.php/dav/files/user1/Noten/OldPath.pdf')");
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Bach')");
		$database->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 10)');
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturnCallback(static fn (string $path): bool => $path === 'OldPath.pdf');
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		$sourceFile = $this->createMock(File::class);
		$sourceFile->method('getContent')->willReturn('PDF');
		$sourceFile->method('getMTime')->willReturn(1);
		$sourceFile->expects($this->once())->method('move')->with('/user1/files/Noten/Bach/Bach - Song 1.pdf')->willReturn($sourceFile);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager(
			$databasePath,
			$targetFolder,
			['Noten/OldPath.pdf' => $sourceFile],
		);
		$userFolder = $rootFolder->getUserFolder('user1');
		$userFolder->method('nodeExists')->willReturnCallback(
			static fn (string $path): bool => in_array($path, ['Noten', 'Noten/OldPath.pdf'], true),
		);
		$userFolder->method('newFolder')->willReturn($this->createMock(Folder::class));

		try {
			$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
			$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);

			$issues = $service->checkIntegrity(1, 'user1');
			self::assertContains(
				IntegrityIssue::TYPE_INVALID_NAMING_RULE,
				array_map(static fn (IntegrityIssue $issue): string => $issue->type, $issues),
			);
			$result = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_INVALID_NAMING_RULE);

			self::assertTrue($result['success']);
			self::assertSame(1, $result['fixedCount']);
			self::assertSame(
				'/remote.php/dav/files/user1/Noten/Bach/Bach - Song 1.pdf',
				$database->query('SELECT Path FROM Files WHERE Id = 100')->fetchColumn(),
			);
		} finally {
			unlink($databasePath);
		}
	}

	public function testCheckIntegrityPopulatesOrphanFileDetails(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('OrphanScore.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/OrphanScore.pdf');
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager(
			$fixture['path'],
			$targetFolder,
		);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);

		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(1, $issues);
		self::assertSame(IntegrityIssue::TYPE_ORPHAN_FILE, $issues[0]->type);
		self::assertSame('OrphanScore.pdf', $issues[0]->details['filename']);
		self::assertSame('/remote.php/dav/files/user1/Noten/OrphanScore.pdf', $issues[0]->details['webdavPath']);
		self::assertSame('Noten/OrphanScore.pdf', $issues[0]->details['userRelativePath']);
		unlink($fixture['path']);
	}

	public function testCheckIntegrityWhenAutoPathsDisabledReportsNoNamingOrOrphanIssues(): void {
		$libraryMapper = $this->createMock(\OCA\MobileSheetsManager\Db\LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('user1', 1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
			'autoPaths' => false,
		]);
		$libraryService = new \OCA\MobileSheetsManager\Service\LibraryService(
			$libraryMapper,
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		// Non-standard filename in root folder
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Custom Song')");
		$fixture['connection']->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/non_standard_name.pdf')");

		$songMapper = $this->createSongMapper();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		// An unreferenced file in target folder (would normally be orphan)
		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('other_unrelated_file.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/other_unrelated_file.pdf');
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		$song = $songMapper->findSongs($fixture['path'])[0];
		$pdf = $this->createMock(\OCP\Files\File::class);
		$pdf->method('getContent')->willReturn(MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			MobileSheetsPdfMetadata::fieldsFor($song),
		));
		$pdf->method('getMTime')->willReturn(1);
		$targetFolder->method('get')->with('non_standard_name.pdf')->willReturn($pdf);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager(
			$fixture['path'],
			$targetFolder,
			['Noten/non_standard_name.pdf' => $pdf],
		);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$service = new LibraryIntegrityService($libraryService, $songMapper, $fileManager, $rootFolder, $pathResolver, $logger);

		$issues = $service->checkIntegrity(1, 'user1');

		// Should not report TYPE_INVALID_NAMING_RULE nor TYPE_ORPHAN_FILE
		self::assertCount(0, $issues);
		unlink($fixture['path']);
	}

	/**
	 * @param array<string, File> $files paths relative to the user's files folder
	 * @return array{FileManager, IRootFolder, MobileSheetsPathResolver}
	 */
	private function createIntegrityFileManager(
		string $databasePath,
		Folder $targetFolder,
		array $files = [],
	): array {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('user1');

		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn($databasePath);

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/library.db');
		$databaseNode->method('getParent')->willReturn($targetFolder);

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/user1/files');
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($databaseNode, $files): Node {
				if ($path === 'Noten/library.db') {
					return $databaseNode;
				}
				if (isset($files[$path])) {
					return $files[$path];
				}
				throw new NotFoundException($path);
			},
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('user1')->willReturn($userFolder);

		$libraryMapper = $this->createMock(\OCA\MobileSheetsManager\Db\LibraryMapper::class);
		$libraryMapper->method('findById')->with(1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
		]);

		$pathResolver = new MobileSheetsPathResolver($rootFolder);
		$fileManager = new FileManager(
			$libraryMapper,
			$this->createSongMapper(),
			$rootFolder,
			$pathResolver,
			$this->createMock(LoggerInterface::class),
		);

		return [$fileManager, $rootFolder, $pathResolver];
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
