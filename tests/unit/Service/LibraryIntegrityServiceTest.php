<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\IntegrityIssue;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\MobileSheetsPathResolver;
use OCA\SheetMusic\Service\MobileSheetsPdfMetadata;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TestSupport\TestUtils;

final class LibraryIntegrityServiceTest extends TestCase {
	use TestUtils;

	private function createIntegrityService(
		LibraryService $libraryService,
		FileManager $fileManager,
		IRootFolder $rootFolder,
		MobileSheetsPathResolver $pathResolver,
		?LoggerInterface $logger = null,
		?InternalEntityMapper $internalEntityMapper = null,
		?InternalSongMapper $internalSongMapper = null,
		?InternalSongFileMapper $internalSongFileMapper = null,
		?LibraryMapper $libraryMapper = null,
		?InternalSongAttributeMapper $internalSongAttributeMapper = null,
		?InternalMiscMapper $internalMiscMapper = null,
		?string $databasePath = null,
	): LibraryIntegrityService {
		if ($internalSongMapper === null) {
			$internalSongMapper = $this->createMock(InternalSongMapper::class);
			if ($databasePath !== null) {
				$songMapper = $this->createSongMapper();
				$internalSongMapper->method('findLibraryData')->willReturnCallback(function () use ($songMapper, $databasePath) {
					return ['songs' => $songMapper->findSongs($databasePath)];
				});
				$internalSongMapper->method('findSong')->willReturnCallback(function (int $songId) use ($songMapper, $databasePath) {
					return $songMapper->findSong($databasePath, $songId);
				});
			} else {
				$internalSongMapper->method('findLibraryData')->willReturn(['songs' => []]);
			}
		}

		return new LibraryIntegrityService(
			$libraryService,
			$fileManager,
			$rootFolder,
			$pathResolver,
			$logger ?? new NullLogger(),
			$internalEntityMapper ?? $this->createMock(InternalEntityMapper::class),
			$internalSongMapper,
			$internalSongFileMapper ?? $this->createMock(InternalSongFileMapper::class),
			$libraryMapper ?? $this->createMock(LibraryMapper::class),
			$internalSongAttributeMapper ?? $this->createMock(InternalSongAttributeMapper::class),
			$internalMiscMapper ?? $this->createMock(InternalMiscMapper::class),
		);
	}

	public function testCheckIntegrityDetectsMissingFile(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Test Song')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/missing.pdf')");
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(false);
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

		try {
			$service = $this->createIntegrityService($libraryService, $fileManager, $rootFolder, $pathResolver, databasePath: $databasePath);
			$issues = $service->checkIntegrity(1, 'user1');

			self::assertCount(1, $issues);
			self::assertSame(IntegrityIssue::TYPE_MISSING_FILE, $issues[0]->type);
			self::assertSame('Test Song', $issues[0]->songTitle);
		} finally {
			unlink($databasePath);
		}
	}

	public function testCheckIntegrityDoesNotReportInvalidNamingRuleIfFileIsMissing(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Joshua')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/OldNonStandardName.pdf')");
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Georg Friedrich Händel')");
		$database->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 1)');

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		// The source file does NOT exist on disk, even if target naming rule path exists
		$targetFolder->method('nodeExists')->willReturnCallback(
			static fn (string $path): bool => $path === 'Händel/Georg Friedrich Händel - Joshua.pdf'
		);
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

		try {
			$service = $this->createIntegrityService($libraryService, $fileManager, $rootFolder, $pathResolver, databasePath: $databasePath);
			$issues = $service->checkIntegrity(1, 'user1');

			$types = array_map(static fn (IntegrityIssue $i): string => $i->type, $issues);
			self::assertContains(IntegrityIssue::TYPE_MISSING_FILE, $types);
			self::assertNotContains(IntegrityIssue::TYPE_INVALID_NAMING_RULE, $types);

			$missingIssues = array_values(array_filter($issues, static fn (IntegrityIssue $i): bool => $i->type === IntegrityIssue::TYPE_MISSING_FILE));
			self::assertCount(1, $missingIssues);
			self::assertSame(IntegrityIssue::SEVERITY_ERROR, $missingIssues[0]->severity);
			self::assertFalse($missingIssues[0]->fixable);
			self::assertStringContainsString('OldNonStandardName.pdf', $missingIssues[0]->message);
		} finally {
			unlink($databasePath);
		}
	}

	public function testScanOrphansIgnoresHashcodeFiles(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/tmp');
		$hashcodeNode = $this->createMock(\OCP\Files\Node::class);
		$hashcodeNode->method('getName')->willReturn('mobilesheets_hashcodes.txt');
		$hashcodeNode->method('getPath')->willReturn('/tmp/mobilesheets_hashcodes.txt');
		$targetFolder->method('getDirectoryListing')->willReturn([$hashcodeNode]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);

		$service = $this->createIntegrityService($libraryService, $fileManager, $rootFolder, $pathResolver, databasePath: $fixture['path']);
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

		$service = $this->createIntegrityService($libraryService, $fileManager, $rootFolder, $pathResolver, databasePath: $fixture['path']);
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

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			databasePath: $fixture['path'],
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
		unlink($fixture['path']);
	}

	public function testCheckIntegritySkipsPdfMetadataWhenDisabled(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Joshua')");
		$fixture['connection']->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/Bach - Joshua.pdf')");
		$fixture['connection']->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Bach')");
		$fixture['connection']->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 1)');
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);

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

		$libraryService = $this->createMock(\OCA\SheetMusic\Service\LibraryService::class);
		$libraryService->method('isAutoPathsEnabled')->willReturn(true);
		$libraryService->method('isAutoPdfMetadataEnabled')->willReturn(false);

		$service = $this->createIntegrityService(
			$libraryService,
			$fileManager,
			$rootFolder,
			$pathResolver,
			databasePath: $fixture['path'],
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
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

		try {
			$service = $this->createIntegrityService($libraryService, $fileManager, $rootFolder, $pathResolver, databasePath: $databasePath);
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

			$targetFolder = $this->createMock(Folder::class);
			$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
			$targetFolder->method('getDirectoryListing')->willReturn([]);
			[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

			$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
			$assignments = [
				['songId' => 7, 'entityType' => 'collections', 'count' => 3],
				['songId' => 7, 'entityType' => 'keys', 'count' => 2],
				['songId' => 8, 'entityType' => 'keys', 'count' => 2],
			];
			$internalEntityMapper->method('findDuplicateSongEntityAssignments')->willReturnCallback(function () use (&$assignments) {
				return $assignments;
			});
			$internalEntityMapper->method('removeDuplicateSongEntityAssignments')->willReturnCallback(function ($libId, $songId) use (&$assignments) {
				$initial = count($assignments);
				$assignments = array_values(array_filter($assignments, fn ($a) => $a['songId'] !== $songId));
				return $initial - count($assignments);
			});

			$service = $this->createIntegrityService(
				$this->createLibraryService(),
				$fileManager,
				$rootFolder,
				$pathResolver,
				internalEntityMapper: $internalEntityMapper,
				databasePath: $databasePath,
			);

			$issues = $service->checkIntegrity(1, 'user1');
			self::assertCount(2, $issues);
			self::assertSame(IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT, $issues[0]->songId === 7 ? $issues[0]->type : $issues[1]->type);

			$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT, 7);
			self::assertTrue($result['success']);
			self::assertSame(1, $result['fixedCount']);

			$remainingIssues = $service->checkIntegrity(1, 'user1');
			self::assertCount(1, $remainingIssues);
			self::assertSame(8, $remainingIssues[0]->songId);

			$batchResult = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT);
			self::assertTrue($batchResult['success']);
			self::assertSame(1, $batchResult['fixedCount']);
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
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

		try {
			$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
			$duplicates = [
				[
					'entityType' => 'composer',
					'name' => 'Bach',
					'keepId' => 4,
					'duplicateIds' => [8],
				],
				[
					'entityType' => 'key',
					'name' => 'C',
					'keepId' => 2,
					'duplicateIds' => [9],
				],
			];
			$internalEntityMapper->method('findDuplicateEntities')->willReturnCallback(function () use (&$duplicates) {
				return $duplicates;
			});
			$internalEntityMapper->method('mergeEntities')->willReturnCallback(function ($libId, $normType, $keepId, $dupIds) use (&$duplicates) {
				$duplicates = array_values(array_filter($duplicates, fn ($d) => !($d['entityType'] === $normType && $d['keepId'] === $keepId)));
				return count($dupIds);
			});

			$service = $this->createIntegrityService(
				$this->createLibraryService(),
				$fileManager,
				$rootFolder,
				$pathResolver,
				internalEntityMapper: $internalEntityMapper,
				databasePath: $databasePath,
			);

			$issues = $service->checkIntegrity(1, 'user1');
			self::assertCount(2, $issues);
			self::assertSame(IntegrityIssue::TYPE_DUPLICATE_ENTITY, $issues[0]->type);
			self::assertTrue($issues[0]->fixable);
			self::assertSame([
				'entityType' => 'composer',
				'name' => 'Bach',
				'keepId' => 4,
				'duplicateIds' => [8],
			], $issues[0]->details);

			$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY, entityType: 'key', entityId: 2);
			self::assertTrue($result['success']);
			self::assertSame(1, $result['fixedCount']);

			$remaining = $service->checkIntegrity(1, 'user1');
			self::assertCount(1, $remaining);

			$batchResult = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_ENTITY);
			self::assertTrue($batchResult['success']);
			self::assertSame(1, $batchResult['fixedCount']);
			self::assertSame([], $service->checkIntegrity(1, 'user1'));
		} finally {
			unlink($databasePath);
		}
	}

	public function testCheckIntegrityFindsDuplicateKeysFromSingularKeyTable(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		try {
			$targetFolder = $this->createMock(Folder::class);
			$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
			$targetFolder->method('getDirectoryListing')->willReturn([]);
			[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($databasePath, $targetFolder);

			$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
			$internalEntityMapper->method('findDuplicateEntities')->willReturn([
				[
					'entityType' => 'key',
					'name' => 'C',
					'keepId' => 35,
					'duplicateIds' => [37],
				],
				[
					'entityType' => 'key',
					'name' => 'F',
					'keepId' => 36,
					'duplicateIds' => [38],
				],
			]);

			$service = $this->createIntegrityService(
				$this->createLibraryService(),
				$fileManager,
				$rootFolder,
				$pathResolver,
				internalEntityMapper: $internalEntityMapper,
				databasePath: $databasePath,
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
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturnCallback(static fn (string $path): bool => $path === 'OldPath.pdf');
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		$sourceFile = $this->createMock(File::class);
		$sourceFile->method('getContent')->willReturn('PDF');
		$sourceFile->method('getMTime')->willReturn(1);
		$sourceFile->expects($this->once())->method('move')->with('/user1/files/Noten/Bach/Bach - Song 1.pdf')->willReturn($sourceFile);
		[$fileManager, $rootFolder, $pathResolver, $internalSongMapper] = $this->createIntegrityFileManager(
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
			$service = $this->createIntegrityService(
				$libraryService,
				$fileManager,
				$rootFolder,
				$pathResolver,
				internalSongMapper: $internalSongMapper,
				databasePath: $databasePath,
			);

			$issues = $service->checkIntegrity(1, 'user1');
			self::assertContains(
				IntegrityIssue::TYPE_INVALID_NAMING_RULE,
				array_map(static fn (IntegrityIssue $issue): string => $issue->type, $issues),
			);
			$result = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_INVALID_NAMING_RULE);

			self::assertTrue($result['success']);
			self::assertSame(1, $result['fixedCount']);
		} finally {
			unlink($databasePath);
		}
	}

	public function testFixIssueFixesSingleInvalidNamingRule(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (10, 'Song 1')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (100, 10, '/remote.php/dav/files/user1/Noten/OldPath.pdf')");
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (1, 'Bach')");
		$database->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 10)');
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturnCallback(static fn (string $path): bool => $path === 'OldPath.pdf');
		$targetFolder->method('getDirectoryListing')->willReturn([]);
		$sourceFile = $this->createMock(File::class);
		$sourceFile->method('getContent')->willReturn('PDF');
		$sourceFile->method('getMTime')->willReturn(1);
		$sourceFile->expects($this->once())->method('move')->with('/user1/files/Noten/Bach/Bach - Song 1.pdf')->willReturn($sourceFile);
		[$fileManager, $rootFolder, $pathResolver, $internalSongMapper] = $this->createIntegrityFileManager(
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
			$service = $this->createIntegrityService(
				$libraryService,
				$fileManager,
				$rootFolder,
				$pathResolver,
				internalSongMapper: $internalSongMapper,
				databasePath: $databasePath,
			);

			$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_INVALID_NAMING_RULE, songId: 10, fileId: 100);

			self::assertTrue($result['success']);
			self::assertSame(1, $result['fixedCount']);
		} finally {
			unlink($databasePath);
		}
	}

	public function testCheckIntegrityPopulatesOrphanFileDetails(): void {
		$libraryService = $this->createLibraryService();
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
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
		$service = $this->createIntegrityService($libraryService, $fileManager, $rootFolder, $pathResolver, databasePath: $fixture['path']);

		$issues = $service->checkIntegrity(1, 'user1');

		self::assertCount(1, $issues);
		self::assertSame(IntegrityIssue::TYPE_ORPHAN_FILE, $issues[0]->type);
		self::assertSame('OrphanScore.pdf', $issues[0]->details['filename']);
		self::assertSame('/remote.php/dav/files/user1/Noten/OrphanScore.pdf', $issues[0]->details['webdavPath']);
		self::assertSame('Noten/OrphanScore.pdf', $issues[0]->details['userRelativePath']);
		unlink($fixture['path']);
	}

	public function testCheckIntegrityWhenAutoPathsDisabledReportsNoNamingOrOrphanIssues(): void {
		$libraryMapper = $this->createMock(\OCA\SheetMusic\Db\LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('user1', 1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
			'autoPaths' => false,
		]);
		$libraryService = new LibraryService(
			$libraryMapper,
			$this->createMock(IRootFolder::class),
			new NullLogger(),
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

		$service = $this->createIntegrityService($libraryService, $fileManager, $rootFolder, $pathResolver, databasePath: $fixture['path']);

		$issues = $service->checkIntegrity(1, 'user1');

		// Should not report TYPE_INVALID_NAMING_RULE nor TYPE_ORPHAN_FILE
		self::assertCount(0, $issues);
		unlink($fixture['path']);
	}

	/**
	 * @param array<string, File> $files paths relative to the user's files folder
	 * @return array{FileManager, IRootFolder, MobileSheetsPathResolver, InternalSongMapper}
	 */
	private function createIntegrityFileManager(
		string $databasePath,
		Folder $targetFolder,
		array $files = [],
		?InternalSongMapper $internalSongMapper = null,
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

		$libraryMapper = $this->createMock(\OCA\SheetMusic\Db\LibraryMapper::class);
		$libraryMapper->method('findById')->with(1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
		]);

		if ($internalSongMapper === null) {
			$songMapper = $this->createSongMapper();
			$internalSongMapper = $this->createMock(InternalSongMapper::class);
			$internalSongMapper->method('findLibraryData')->willReturnCallback(function () use ($songMapper, $databasePath) {
				return ['songs' => $songMapper->findSongs($databasePath)];
			});
			$internalSongMapper->method('findSong')->willReturnCallback(function (int $songId) use ($songMapper, $databasePath) {
				return $songMapper->findSong($databasePath, $songId);
			});
		}

		$pathResolver = new MobileSheetsPathResolver($rootFolder);
		$fileManager = new FileManager(
			$libraryMapper,
			$rootFolder,
			$pathResolver,
			new NullLogger(),
			null,
			null,
			$internalSongMapper,
		);

		return [$fileManager, $rootFolder, $pathResolver, $internalSongMapper];
	}

	public function testCheckIntegritySongWithoutNamingRuleKeepsExistingName(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];
		// Song with no composer, artist, or collection
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (5, 'Song Without Rule')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (50, 5, '/remote.php/dav/files/user1/Noten/Song Without Rule.pdf')");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturnCallback(static fn (string $path): bool => $path === 'Song Without Rule.pdf');
		$targetFolder->method('getDirectoryListing')->willReturn([]);

		$pdf = $this->createMock(\OCP\Files\File::class);
		$pdf->method('getContent')->willReturn(self::minimalPdf());
		$pdf->method('getMTime')->willReturn(1);
		$targetFolder->method('get')->with('Song Without Rule.pdf')->willReturn($pdf);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager(
			$databasePath,
			$targetFolder,
			['Noten/Song Without Rule.pdf' => $pdf],
		);

		$libraryService = $this->createLibraryService();
		$service = $this->createIntegrityService($libraryService, $fileManager, $rootFolder, $pathResolver, databasePath: $databasePath);

		try {
			$issues = $service->checkIntegrity(1, 'user1');
			// Should not report invalid_naming_rule
			$namingIssues = array_filter($issues, static fn (IntegrityIssue $issue): bool => $issue->type === IntegrityIssue::TYPE_INVALID_NAMING_RULE);
			self::assertCount(0, $namingIssues);
		} finally {
			unlink($databasePath);
		}
	}

	public function testCheckIntegrityDetectsOrphanEntityAssignments(): void {
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$entityMapper->method('findOrphanSongEntityAssignments')->with(1)->willReturn([
			['id' => 1, 'song_id' => 999, 'entity_id' => 10, 'entity_type' => 'composers'],
		]);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalEntityMapper: $entityMapper,
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanIssues = array_values(array_filter($issues, static fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT));
		self::assertCount(1, $orphanIssues);
		self::assertTrue($orphanIssues[0]->fixable);
		self::assertSame(IntegrityIssue::SEVERITY_WARNING, $orphanIssues[0]->severity);
	}

	public function testFixIssueRemovesOrphanEntityAssignments(): void {
		$targetFolder = $this->createMock(Folder::class);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$entityMapper->expects(self::once())->method('removeOrphanSongEntityAssignments')->with(1)->willReturn(2);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalEntityMapper: $entityMapper,
			libraryMapper: $libraryMapper,
		);

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);
		self::assertStringContainsString('Removed 2 orphan entity assignment(s)', $result['message']);
	}

	public function testCheckIntegrityDetectsDuplicateClientIds(): void {
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);

		$songMapper = $this->createMock(InternalSongMapper::class);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);
		$songMapper->method('findDuplicateClientIds')->with(1)->willReturn([
			['client_id' => 123, 'keep_id' => 1, 'duplicate_ids' => [2], 'count' => 2],
		]);

		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$entityMapper->method('findDuplicateClientIds')->with(1)->willReturn([
			['client_id' => 456, 'entity_type' => 'composers', 'keep_id' => 10, 'duplicate_ids' => [11], 'count' => 2],
		]);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalEntityMapper: $entityMapper,
			internalSongMapper: $songMapper,
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$dupIssues = array_values(array_filter($issues, static fn ($i) => $i->type === IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID));
		self::assertCount(2, $dupIssues);
		self::assertTrue($dupIssues[0]->fixable);
		self::assertTrue($dupIssues[1]->fixable);
	}

	public function testFixIssueFixesDuplicateClientIds(): void {
		$targetFolder = $this->createMock(Folder::class);
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);

		$songMapper = $this->createMock(InternalSongMapper::class);
		$songMapper->expects(self::once())->method('fixDuplicateClientIds')->with(1)->willReturn(1);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$entityMapper->expects(self::once())->method('fixDuplicateClientIds')->with(1)->willReturn(2);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalEntityMapper: $entityMapper,
			internalSongMapper: $songMapper,
			libraryMapper: $libraryMapper,
		);

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);
		self::assertStringContainsString('Reset 3 duplicate client_id record(s)', $result['message']);
	}

	public function testCheckIntegrityDetectsSongsWithoutFiles(): void {
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);

		$songMapper = $this->createMock(InternalSongMapper::class);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);
		$songMapper->method('findSongsWithoutFiles')->with(1)->willReturn([
			['id' => 42, 'title' => 'Empty Song', 'client_id' => null],
		]);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalSongMapper: $songMapper,
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$emptyIssues = array_values(array_filter($issues, static fn ($i) => $i->type === IntegrityIssue::TYPE_SONG_WITHOUT_FILES));
		self::assertCount(1, $emptyIssues);
		self::assertSame(42, $emptyIssues[0]->songId);
		self::assertSame('Empty Song', $emptyIssues[0]->songTitle);
		self::assertFalse($emptyIssues[0]->fixable);
	}

	public function testCheckIntegrityAndFixOrphanSongFiles(): void {
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);

		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$songFileMapper->method('findOrphanSongFiles')->with(1)->willReturn([
			['id' => 10, 'songId' => 999, 'fileId' => 123, 'uuid' => 'abc'],
		]);
		$songFileMapper->expects(self::once())->method('removeOrphanSongFiles')->with(1)->willReturn(1);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalSongFileMapper: $songFileMapper,
			libraryMapper: $libraryMapper,
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanFiles = array_values(array_filter($issues, static fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_SONG_FILE));
		self::assertCount(1, $orphanFiles);
		self::assertTrue($orphanFiles[0]->fixable);

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_ORPHAN_SONG_FILE);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);
	}

	public function testCheckIntegrityAndFixOrphanSongAttributes(): void {
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);

		$songAttrMapper = $this->createMock(InternalSongAttributeMapper::class);
		$songAttrMapper->method('findOrphanSongAttributes')->with(1)->willReturn([
			['id' => 5, 'song_id' => 999, 'key' => 'custom'],
		]);
		$songAttrMapper->expects(self::once())->method('removeOrphanSongAttributes')->with(1)->willReturn(1);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			libraryMapper: $libraryMapper,
			internalSongAttributeMapper: $songAttrMapper,
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanAttrs = array_values(array_filter($issues, static fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE));
		self::assertCount(1, $orphanAttrs);
		self::assertTrue($orphanAttrs[0]->fixable);

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);
	}

	public function testCheckIntegrityAndFixOrphanMiscData(): void {
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);

		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$miscMapper->method('findOrphanMiscData')->with(1)->willReturn([
			['id' => 7, 'song_id' => 999],
		]);
		$miscMapper->expects(self::once())->method('removeOrphanMiscData')->with(1)->willReturn(1);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			libraryMapper: $libraryMapper,
			internalMiscMapper: $miscMapper,
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanMisc = array_values(array_filter($issues, static fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_MISC_DATA));
		self::assertCount(1, $orphanMisc);
		self::assertTrue($orphanMisc[0]->fixable);

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_ORPHAN_MISC_DATA);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);
	}

	public function testFixAllIssuesHandlesRelationalIntegrityIssues(): void {
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager('dummy.db', $targetFolder);

		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$entityMapper->method('findOrphanSongEntityAssignments')->with(1)->willReturn([
			['id' => 1, 'song_id' => 999, 'entity_id' => 10, 'entity_type' => 'composers'],
		]);
		$entityMapper->expects(self::once())->method('removeOrphanSongEntityAssignments')->with(1)->willReturn(1);
		$entityMapper->method('findDuplicateClientIds')->with(1)->willReturn([
			['client_id' => 456, 'entity_type' => 'composers', 'keep_id' => 10, 'duplicate_ids' => [11], 'count' => 2],
		]);
		$entityMapper->expects(self::once())->method('fixDuplicateClientIds')->with(1)->willReturn(1);

		$songMapper = $this->createMock(InternalSongMapper::class);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);
		$songMapper->method('findDuplicateClientIds')->with(1)->willReturn([]);
		$songMapper->method('fixDuplicateClientIds')->with(1)->willReturn(0);

		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$songFileMapper->method('findOrphanSongFiles')->with(1)->willReturn([
			['id' => 10, 'songId' => 999, 'fileId' => 123, 'uuid' => 'abc'],
		]);
		$songFileMapper->expects(self::once())->method('removeOrphanSongFiles')->with(1)->willReturn(1);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::atLeastOnce())->method('touch')->with(1);

		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalEntityMapper: $entityMapper,
			internalSongMapper: $songMapper,
			internalSongFileMapper: $songFileMapper,
			libraryMapper: $libraryMapper,
		);

		$result = $service->fixAllIssues(1, 'user1');
		self::assertTrue($result['success']);
		self::assertSame(3, $result['fixedCount']);
	}

	public function testOrphanFileAutofixUniqueTitle(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Moonlight Sonata')");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('Moonlight Sonata.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/Moonlight Sonata.pdf');
		$orphanNode->method('getId')->willReturn(101);
		$orphanNode->method('getSize')->willReturn(12345);
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);
		$targetFolder->method('get')->with('Moonlight Sonata.pdf')->willReturn($orphanNode);

		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$songFileMapper->method('findAllForSong')->willReturn([]);
		$songFileMapper->expects(self::once())->method('insert');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::atLeastOnce())->method('touch')->with(1);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);
		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalSongFileMapper: $songFileMapper,
			libraryMapper: $libraryMapper,
			databasePath: $fixture['path'],
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanIssues = array_values(array_filter($issues, fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_FILE));
		self::assertCount(1, $orphanIssues);
		self::assertTrue($orphanIssues[0]->fixable);
		self::assertSame(1, $orphanIssues[0]->songId);
		self::assertSame('Moonlight Sonata', $orphanIssues[0]->songTitle);
		self::assertSame(1, $orphanIssues[0]->details['matchedSongId']);
		self::assertSame('Moonlight Sonata', $orphanIssues[0]->details['matchedSongTitle']);

		$result = $service->fixIssue(1, 'user1', IntegrityIssue::TYPE_ORPHAN_FILE, path: 'Moonlight Sonata.pdf');
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);

		$filesInDb = $database->query("SELECT * FROM Files WHERE SongId = 1")->fetchAll();
		self::assertCount(1, $filesInDb);
		self::assertSame('Moonlight Sonata.pdf', $filesInDb[0]['Path']);

		unlink($fixture['path']);
	}

	public function testOrphanFileAutofixDeepenToComposer(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Sonata')");
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (2, 'Sonata')");
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (10, 'Beethoven')");
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (20, 'Mozart')");
		$database->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (10, 1)");
		$database->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (20, 2)");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('Sonata.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/Beethoven/Sonata.pdf');
		$orphanNode->method('getId')->willReturn(101);
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);
		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			databasePath: $fixture['path'],
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanIssues = array_values(array_filter($issues, fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_FILE));
		self::assertCount(1, $orphanIssues);
		self::assertTrue($orphanIssues[0]->fixable);
		self::assertSame(1, $orphanIssues[0]->songId);
		self::assertSame('Sonata', $orphanIssues[0]->songTitle);

		unlink($fixture['path']);
	}

	public function testOrphanFileAutofixDeepenToArtist(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Sonata')");
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (2, 'Sonata')");
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (10, 'Beethoven')");
		$database->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (10, 1)");
		$database->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (10, 2)");
		$database->exec("INSERT INTO Artists (Id, Name) VALUES (100, 'Kempff')");
		$database->exec("INSERT INTO Artists (Id, Name) VALUES (200, 'Barenboim')");
		$database->exec("INSERT INTO ArtistsSongs (ArtistId, SongId) VALUES (100, 1)");
		$database->exec("INSERT INTO ArtistsSongs (ArtistId, SongId) VALUES (200, 2)");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('Beethoven - Barenboim - Sonata.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/Beethoven - Barenboim - Sonata.pdf');
		$orphanNode->method('getId')->willReturn(101);
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);
		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			databasePath: $fixture['path'],
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanIssues = array_values(array_filter($issues, fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_FILE));
		self::assertCount(1, $orphanIssues);
		self::assertTrue($orphanIssues[0]->fixable);
		self::assertSame(2, $orphanIssues[0]->songId);

		unlink($fixture['path']);
	}

	public function testOrphanFileAutofixDeepenToCollection(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Sonata')");
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (2, 'Sonata')");
		$database->exec("INSERT INTO Collections (Id, Name) VALUES (10, 'Classics')");
		$database->exec("INSERT INTO Collections (Id, Name) VALUES (20, 'Romantic')");
		$database->exec("INSERT INTO CollectionSong (CollectionId, SongId) VALUES (10, 1)");
		$database->exec("INSERT INTO CollectionSong (CollectionId, SongId) VALUES (20, 2)");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('Sonata.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/Classics/Sonata.pdf');
		$orphanNode->method('getId')->willReturn(101);
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);
		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			databasePath: $fixture['path'],
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanIssues = array_values(array_filter($issues, fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_FILE));
		self::assertCount(1, $orphanIssues);
		self::assertTrue($orphanIssues[0]->fixable);
		self::assertSame(1, $orphanIssues[0]->songId);

		unlink($fixture['path']);
	}

	public function testOrphanFileAutofixDeepenToBook(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Sonata')");
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (2, 'Sonata')");
		$database->exec("INSERT INTO Books (Id, Title) VALUES (10, 'Book A')");
		$database->exec("INSERT INTO Books (Id, Title) VALUES (20, 'Book B')");
		$database->exec("INSERT INTO BookSongs (BookId, SongId) VALUES (10, 1)");
		$database->exec("INSERT INTO BookSongs (BookId, SongId) VALUES (20, 2)");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('Sonata.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/Book B/Sonata.pdf');
		$orphanNode->method('getId')->willReturn(101);
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);
		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			databasePath: $fixture['path'],
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanIssues = array_values(array_filter($issues, fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_FILE));
		self::assertCount(1, $orphanIssues);
		self::assertTrue($orphanIssues[0]->fixable);
		self::assertSame(2, $orphanIssues[0]->songId);

		unlink($fixture['path']);
	}

	public function testOrphanFileCannotDisambiguateMultipleCandidates(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Sonata')");
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (2, 'Sonata')");
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (10, 'Beethoven')");
		$database->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (1, 10)");
		$database->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (2, 10)");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('Sonata.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/Beethoven/Sonata.pdf');
		$orphanNode->method('getId')->willReturn(101);
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);
		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			databasePath: $fixture['path'],
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanIssues = array_values(array_filter($issues, fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_FILE));
		self::assertCount(1, $orphanIssues);
		self::assertFalse($orphanIssues[0]->fixable);
		self::assertNull($orphanIssues[0]->songId);

		unlink($fixture['path']);
	}

	public function testOrphanFileExcludedWhenSongAlreadyHasFiles(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Moonlight Sonata')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (10, 1, '/remote.php/dav/files/user1/Noten/existing.pdf')");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('Moonlight Sonata.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/Moonlight Sonata.pdf');
		$orphanNode->method('getId')->willReturn(101);
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);
		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			databasePath: $fixture['path'],
		);

		$issues = $service->checkIntegrity(1, 'user1');
		$orphanIssues = array_values(array_filter($issues, fn ($i) => $i->type === IntegrityIssue::TYPE_ORPHAN_FILE));
		self::assertCount(1, $orphanIssues);
		self::assertFalse($orphanIssues[0]->fixable);
		self::assertNull($orphanIssues[0]->songId);

		unlink($fixture['path']);
	}

	public function testFixAllIssuesResolvesOrphanFiles(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$database = $fixture['connection'];
		$database->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Moonlight Sonata')");

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/user1/files/Noten');
		$targetFolder->method('nodeExists')->willReturn(true);

		$orphanNode = $this->createMock(\OCP\Files\Node::class);
		$orphanNode->method('getName')->willReturn('Moonlight Sonata.pdf');
		$orphanNode->method('getPath')->willReturn('/user1/files/Noten/Moonlight Sonata.pdf');
		$orphanNode->method('getId')->willReturn(101);
		$orphanNode->method('getSize')->willReturn(12345);
		$targetFolder->method('getDirectoryListing')->willReturn([$orphanNode]);
		$targetFolder->method('get')->with('Moonlight Sonata.pdf')->willReturn($orphanNode);

		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$songFileMapper->method('findAllForSong')->willReturn([]);
		$songFileMapper->expects(self::once())->method('insert');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::atLeastOnce())->method('touch')->with(1);

		[$fileManager, $rootFolder, $pathResolver] = $this->createIntegrityFileManager($fixture['path'], $targetFolder);
		$service = $this->createIntegrityService(
			$this->createLibraryService(),
			$fileManager,
			$rootFolder,
			$pathResolver,
			internalSongFileMapper: $songFileMapper,
			libraryMapper: $libraryMapper,
			databasePath: $fixture['path'],
		);

		$result = $service->fixAllIssues(1, 'user1', IntegrityIssue::TYPE_ORPHAN_FILE);
		self::assertTrue($result['success']);
		self::assertSame(1, $result['fixedCount']);

		unlink($fixture['path']);
	}

	private static function minimalPdf(): string {
		return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \ntrailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n164\n%%EOF";
	}
}
