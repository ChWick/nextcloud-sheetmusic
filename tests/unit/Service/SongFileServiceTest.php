<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Db\MobileSheetsFile;
use OCA\SheetMusic\Db\MobileSheetsSong;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\MobileSheetsPathResolver;
use OCA\SheetMusic\Service\SongFileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TestSupport\TestUtils;

final class SongFileServiceTest extends TestCase {
	use TestUtils;
	private const UID = 'user1';

	private IRootFolder $rootFolder;
	private IUserFolder $userFolder;
	private LibraryService $libraryService;
	private LibraryMapper $libraryMapper;
	private InternalSongFileMapper $internalSongFileMapper;
	private InternalSongMapper $internalSongMapper;
	private FileManager $fileManager;
	private LoggerInterface $logger;
	private SongFileService $service;

	/** @var array<string, string> relative path => content */
	private array $files = [];

	/** @var array<string, true> relative path => true */
	private array $folders = [];

	protected function setUp(): void {
		$this->files = [];
		$this->folders = ['Noten' => true];

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);

		$this->userFolder = $this->createMock(IUserFolder::class);
		$this->userFolder->method('getPath')->willReturn('/' . self::UID . '/files');

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with(self::UID)->willReturn($this->userFolder);

		$this->libraryMapper = $this->createMock(LibraryMapper::class);
		$this->libraryMapper->method('findForUser')->with(self::UID, 1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
			'autoPaths' => true,
		]);

		$this->internalSongFileMapper = $this->createMock(InternalSongFileMapper::class);
		$this->internalSongMapper = $this->createMock(InternalSongMapper::class);
		$this->internalSongMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$this->logger = $this->createMock(LoggerInterface::class);

		$this->libraryService = new LibraryService(
			$this->libraryMapper,
			$this->rootFolder,
			$this->logger,
		);

		$pathResolver = new MobileSheetsPathResolver($this->rootFolder);
		$this->fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$pathResolver,
			$this->logger,
			$this->libraryService,
			$this->internalSongFileMapper,
			$this->internalSongMapper,
		);

		$this->service = new SongFileService(
			$this->libraryService,
			$this->fileManager,
			$this->rootFolder,
			$this->logger,
			$this->internalSongFileMapper,
			$this->internalSongMapper,
			$this->libraryMapper,
		);

		// Wire nodeExists, get, and fake nodes to in-memory tree
		$this->userFolder->method('nodeExists')->willReturnCallback(
			fn (string $path) => isset($this->files[ltrim($path, '/')]) || isset($this->folders[ltrim($path, '/')])
		);

		$this->userFolder->method('get')->willReturnCallback(function (string $path): Node {
			$clean = ltrim($path, '/');
			if (isset($this->folders[$clean])) {
				return $this->fakeFolderNode($clean);
			}
			return $this->fakeFileNode($clean);
		});

		$this->userFolder->method('newFolder')->willReturnCallback(function (string $path): Folder {
			$clean = ltrim($path, '/');
			$this->folders[$clean] = true;
			return $this->fakeFolderNode($clean);
		});

		$this->userFolder->method('newFile')->willReturnCallback(function (string $path): File {
			$clean = ltrim($path, '/');
			if (isset($this->files[$clean])) {
				self::fail('newFile called for path that already exists: ' . $clean);
			}
			$this->files[$clean] = '';
			return $this->fakeFileNode($clean);
		});
	}

	private function createSongFileService(
		?LibraryService $libraryService = null,
		?FileManager $fileManager = null,
		?IRootFolder $rootFolder = null,
		?LoggerInterface $logger = null,
		?InternalSongFileMapper $internalSongFileMapper = null,
		?InternalSongMapper $internalSongMapper = null,
		?LibraryMapper $libraryMapper = null,
	): SongFileService {
		return new SongFileService(
			$libraryService ?? $this->libraryService,
			$fileManager ?? $this->fileManager,
			$rootFolder ?? $this->rootFolder,
			$logger ?? $this->logger,
			$internalSongFileMapper ?? $this->internalSongFileMapper,
			$internalSongMapper ?? $this->internalSongMapper,
			$libraryMapper ?? $this->libraryMapper,
		);
	}

	private function fakeFolderNode(string $relative): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/' . self::UID . '/files/' . $relative);
		$folder->method('get')->willReturnCallback(function (string $name) use ($relative): Node {
			$sub = ($relative === '' ? '' : $relative . '/') . $name;
			return $this->fakeFileNode($sub);
		});
		return $folder;
	}

	private function fakeFileNode(string $relative): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getName')->willReturn(basename($relative));
		$file->method('getPath')->willReturn('/' . self::UID . '/files/' . $relative);
		$file->method('getType')->willReturn('file');

		$file->method('move')->willReturnCallback(function (string $targetInternalPath) use ($relative): Node {
			$prefix = '/' . self::UID . '/files/';
			self::assertTrue(str_starts_with($targetInternalPath, $prefix));
			$targetRel = substr($targetInternalPath, strlen($prefix));

			if (isset($this->files[$targetRel])) {
				self::fail('move() attempted to overwrite existing file at: ' . $targetRel);
			}

			$this->files[$targetRel] = $this->files[$relative] ?? 'CONTENT';
			unset($this->files[$relative]);

			return $this->fakeFileNode($targetRel);
		});

		$file->method('copy')->willReturnCallback(function (string $targetInternalPath) use ($relative): Node {
			$prefix = '/' . self::UID . '/files/';
			self::assertTrue(str_starts_with($targetInternalPath, $prefix));
			$targetRel = substr($targetInternalPath, strlen($prefix));

			if (isset($this->files[$targetRel])) {
				self::fail('copy() attempted to overwrite existing file at: ' . $targetRel);
			}

			$this->files[$targetRel] = $this->files[$relative] ?? 'CONTENT';

			return $this->fakeFileNode($targetRel);
		});

		$file->method('putContent')->willReturnCallback(function (string $content) use ($relative): int {
			$this->files[$relative] = $content;
			return strlen($content);
		});

		return $file;
	}

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);
		return $user;
	}

	public function testAttachNextcloudFileCopiesWithoutOverwritingExistingFile(): void {
		// Existing file at destination in library
		$this->files['Noten/score.pdf'] = 'EXISTING_DESTINATION';
		// Source file outside library
		$this->files['Uploads/score.pdf'] = 'NEW_ATTACHMENT';

		$this->service->attachNextcloudFile(
			$this->user(),
			1,
			10,
			'/Uploads/score.pdf',
			'copy',
		);

		self::assertArrayHasKey('Noten/score.pdf', $this->files);
		self::assertSame('EXISTING_DESTINATION', $this->files['Noten/score.pdf'], 'Existing destination file must NOT be overwritten');

		self::assertArrayHasKey('Noten/score (2).pdf', $this->files);
		self::assertSame('NEW_ATTACHMENT', $this->files['Noten/score (2).pdf'], 'New attachment must be copied to non-conflicting unique name');
	}

	public function testAttachNextcloudFileMovesWithoutOverwritingExistingFile(): void {
		// Existing file at destination in library
		$this->files['Noten/score.pdf'] = 'EXISTING_DESTINATION';
		// Source file outside library
		$this->files['Uploads/score.pdf'] = 'NEW_ATTACHMENT';

		$this->service->attachNextcloudFile(
			$this->user(),
			1,
			10,
			'/Uploads/score.pdf',
			'move',
		);

		self::assertArrayHasKey('Noten/score.pdf', $this->files);
		self::assertSame('EXISTING_DESTINATION', $this->files['Noten/score.pdf'], 'Existing destination file must NOT be overwritten');

		self::assertArrayHasKey('Noten/score (2).pdf', $this->files);
		self::assertSame('NEW_ATTACHMENT', $this->files['Noten/score (2).pdf'], 'New attachment must be moved to non-conflicting unique name');
		self::assertArrayNotHasKey('Uploads/score.pdf', $this->files, 'Source file must be moved');
	}

	public function testUploadSongFileWithoutOverwritingExistingFile(): void {
		// Existing file at destination in library
		$this->files['Noten/uploaded.pdf'] = 'EXISTING_FILE';

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
		file_put_contents($tmpFile, 'UPLOADED_CONTENT');

		$fileInfo = [
			'tmp_name' => $tmpFile,
			'name' => 'uploaded.pdf',
		];

		$this->service->uploadSongFile(
			$this->user(),
			1,
			10,
			$fileInfo,
		);

		unlink($tmpFile);

		self::assertArrayHasKey('Noten/uploaded.pdf', $this->files);
		self::assertSame('EXISTING_FILE', $this->files['Noten/uploaded.pdf'], 'Existing file must NOT be overwritten');

		self::assertArrayHasKey('Noten/uploaded (2).pdf', $this->files);
		self::assertSame('UPLOADED_CONTENT', $this->files['Noten/uploaded (2).pdf']);
	}

	public function testUploadSongFileSyncsMetadataAfterAttachingItToTheSong(): void {
		$user = $this->user();
		$userFolder = $this->createMock(IUserFolder::class);
		$targetFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with(self::UID)->willReturn($userFolder);
		$userFolder->method('get')->with('Noten')->willReturn($targetFolder);
		$userFolder->method('nodeExists')->willReturn(false);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getPath')->willReturn('/user1/files/Noten/new.pdf');
		$file->method('getName')->willReturn('new.pdf');
		$file->method('getContent')->willReturn('%PDF-1.4');
		$file->method('getMTime')->willReturn(1);
		$file->method('putContent')->willReturnCallback(static fn (string $content): int => strlen($content));
		$userFolder->method('newFile')->with('Noten/new.pdf')->willReturn($file);

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->expects(self::once())
			->method('uniqueRelativePath')
			->with($userFolder, 'Noten/new.pdf')
			->willReturn('Noten/new.pdf');
		$fileManager->expects(self::once())
			->method('syncMetadataIntoFile')
			->with(
				self::callback(static fn ($song): bool => $song->title === 'New Song'),
				self::callback(static fn ($songFile): bool => $songFile->path === '/remote.php/dav/files/user1/Noten/new.pdf'),
				$user,
				'',
			)
			->willReturn(\OCA\SheetMusic\Service\MobileSheetsFileLogEntry::CODE_METADATA_UPDATED);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with(self::UID, 1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
			'autoPaths' => true,
		]);

		$libraryService = new LibraryService(
			$libraryMapper,
			$rootFolder,
			$this->logger,
		);

		$song = new MobileSheetsSong(
			id: 10,
			title: 'New Song',
			files: [new MobileSheetsFile(1, '/remote.php/dav/files/user1/Noten/new.pdf')],
		);
		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->method('findLibraryData')->willReturn(['songs' => [$song]]);

		$service = $this->createSongFileService(
			libraryService: $libraryService,
			fileManager: $fileManager,
			rootFolder: $rootFolder,
			internalSongMapper: $internalSongMapper,
			libraryMapper: $libraryMapper,
		);

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
		file_put_contents($tmpFile, '%PDF-1.4');

		try {
			$service->uploadSongFile($user, 1, 10, [
				'tmp_name' => $tmpFile,
				'name' => 'new.pdf',
			]);
		} finally {
			unlink($tmpFile);
		}
	}

	public function testDetachSongFileKeepsThePhysicalFile(): void {
		$this->files['Noten/score.pdf'] = 'DETACHED_CONTENT';

		$this->internalSongFileMapper->expects(self::once())
			->method('delete')
			->with(1);
		$this->libraryMapper->expects(self::once())
			->method('touch')
			->with(1);

		$result = $this->service->detachSongFile($this->user(), 1, 10, 1);

		self::assertSame([], $result['songs']);
		self::assertSame('DETACHED_CONTENT', $this->files['Noten/score.pdf']);
	}

	public function testUploadSongFileFailsWhenAutoPathsDisabledAndFileExists(): void {
		$user = $this->user();
		$userFolder = $this->createMock(IUserFolder::class);
		$targetFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with(self::UID)->willReturn($userFolder);
		$userFolder->method('nodeExists')->with('Noten/existing.pdf')->willReturn(true);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with(self::UID, 1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
			'autoPaths' => false,
		]);

		$userFolder->method('get')->willReturnCallback(function (string $path) use ($targetFolder): Node {
			if ($path === 'Noten') {
				return $targetFolder;
			}
			throw new \OCP\Files\NotFoundException($path);
		});

		$libraryService = new LibraryService(
			$libraryMapper,
			$rootFolder,
			$this->logger,
		);

		$service = $this->createSongFileService(
			libraryService: $libraryService,
			rootFolder: $rootFolder,
			libraryMapper: $libraryMapper,
		);

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
		file_put_contents($tmpFile, '%PDF-1.4');

		try {
			$this->expectException(\OCA\SheetMusic\Service\FileAlreadyExistsException::class);
			$service->uploadSongFile($user, 1, 10, [
				'tmp_name' => $tmpFile,
				'name' => 'existing.pdf',
			]);
		} finally {
			unlink($tmpFile);
		}
	}

	public function testUploadSongFileSucceedsWithoutRelocationWhenAutoPathsDisabled(): void {
		$user = $this->user();
		$userFolder = $this->createMock(IUserFolder::class);
		$targetFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with(self::UID)->willReturn($userFolder);
		$userFolder->method('nodeExists')->willReturnCallback(function (string $path): bool {
			if ($path === 'Noten') {
				return true;
			}
			return false;
		});

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getName')->willReturn('new.pdf');
		$file->method('getPath')->willReturn('/user1/files/Noten/new.pdf');
		$file->method('getContent')->willReturn('%PDF-1.4');
		$file->method('getMTime')->willReturn(1);
		$file->method('putContent')->willReturnCallback(static fn (string $content): int => strlen($content));
		$userFolder->method('newFile')->with('Noten/new.pdf')->willReturn($file);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with(self::UID, 1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
			'autoPaths' => false,
		]);

		$userFolder->method('get')->willReturnCallback(function (string $path) use ($targetFolder): Node {
			if ($path === 'Noten') {
				return $targetFolder;
			}
			throw new \OCP\Files\NotFoundException($path);
		});

		$libraryService = new LibraryService(
			$libraryMapper,
			$rootFolder,
			$this->logger,
		);

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->expects(self::never())->method('uniqueRelativePath');
		$fileManager->expects(self::never())->method('regenerateSongFilePaths');
		$fileManager->expects(self::once())
			->method('syncMetadataIntoFile')
			->willReturn(\OCA\SheetMusic\Service\MobileSheetsFileLogEntry::CODE_METADATA_UPDATED);

		$song = new MobileSheetsSong(
			id: 10,
			title: 'Song',
			files: [new MobileSheetsFile(1, '/remote.php/dav/files/user1/Noten/new.pdf')],
		);
		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->method('findLibraryData')->willReturn(['songs' => [$song]]);

		$service = $this->createSongFileService(
			libraryService: $libraryService,
			fileManager: $fileManager,
			rootFolder: $rootFolder,
			internalSongMapper: $internalSongMapper,
			libraryMapper: $libraryMapper,
		);

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
		file_put_contents($tmpFile, '%PDF-1.4');

		try {
			$result = $service->uploadSongFile($user, 1, 10, [
				'tmp_name' => $tmpFile,
				'name' => 'new.pdf',
			]);
			self::assertNotNull($result);
		} finally {
			unlink($tmpFile);
		}
	}

	public function testSyncSongFileMetadataSkippedWhenAutoPdfMetadataDisabled(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->expects(self::once())
			->method('isAutoPdfMetadataEnabled')
			->with(self::UID, 1)
			->willReturn(false);

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->expects(self::never())->method('syncMetadataIntoFile');

		$service = $this->createSongFileService(
			libraryService: $libraryService,
			fileManager: $fileManager,
		);

		$service->syncSongFileMetadata($user, '', 10, 1);
	}
}
