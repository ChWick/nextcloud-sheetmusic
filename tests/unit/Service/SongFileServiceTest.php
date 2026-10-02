<?php

declare(strict_types=1);

namespace Service;

use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCA\MobileSheetsManager\Service\SongFileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SongFileServiceTest extends TestCase {
	private const UID = 'user1';

	private IRootFolder $rootFolder;
	private IUserFolder $userFolder;
	private LibraryService $libraryService;
	private SongMapper $songMapper;
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

		$this->libraryService = $this->createMock(LibraryService::class);
		$this->libraryService->method('getLibraryAndDatabasePath')
			->willReturn(['/Noten/library.db', '/tmp/library.db']);

		$this->songMapper = $this->createMock(SongMapper::class);
		$mockSong = new \OCA\MobileSheetsManager\Db\MobileSheetsSong(
			10,
			'score',
			[new \OCA\MobileSheetsManager\Db\MobileSheetsFile(1, '/remote.php/dav/files/user1/Noten/score (2).pdf')],
			[],
			[],
			[],
			[],
		);
		$this->songMapper->method('findSong')->willReturn($mockSong);
		$this->logger = $this->createMock(LoggerInterface::class);
		$pathResolver = new \OCA\MobileSheetsManager\Service\MobileSheetsPathResolver($this->rootFolder);
		$this->fileManager = new FileManager(
			$this->createMock(\OCA\MobileSheetsManager\Db\LibraryMapper::class),
			$this->songMapper,
			$this->rootFolder,
			$pathResolver,
			$this->logger,
		);

		$this->service = new SongFileService(
			$this->libraryService,
			$this->songMapper,
			$this->fileManager,
			$this->rootFolder,
			$this->logger,
		);

		// Wire nodeExists, get, and fake nodes to in-memory tree
		$this->userFolder->method('nodeExists')->willReturnCallback(
			fn(string $path) => isset($this->files[ltrim($path, '/')]) || isset($this->folders[ltrim($path, '/')])
		);

		$this->userFolder->method('get')->willReturnCallback(function (string $path): Node {
			$clean = ltrim($path, '/');
			if (isset($this->folders[$clean])) {
				return $this->fakeFolderNode($clean);
			}
			return $this->fakeFileNode($clean);
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
}
