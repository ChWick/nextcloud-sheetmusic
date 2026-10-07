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
use TestSupport\TestUtils;

final class SongFileServiceTest extends TestCase {
	use TestUtils;
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
	private array $databaseFixture;

	protected function setUp(): void {
		$this->databaseFixture = \TestSupport\MobileSheetsTestDatabase::create();
		$this->databaseFixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (10, 'score')");
		$this->databaseFixture['connection']->exec("INSERT INTO Files (Id, SongId, Path) VALUES (1, 10, '/remote.php/dav/files/user1/Noten/score (2).pdf')");
		$this->files = [];
		$this->folders = ['Noten' => true];

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);

		$this->userFolder = $this->createMock(IUserFolder::class);
		$this->userFolder->method('getPath')->willReturn('/' . self::UID . '/files');

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with(self::UID)->willReturn($this->userFolder);

		$this->libraryService = $this->createLibraryServiceForDatabase(
			self::UID,
			1,
			'/Noten/library.db',
			$this->databaseFixture['path'],
		);

		$this->songMapper = $this->createSongMapper();
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

	protected function tearDown(): void {
		if (isset($this->databaseFixture['path']) && is_file($this->databaseFixture['path'])) {
			unlink($this->databaseFixture['path']);
		}
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

	public function testUploadSongFileSyncsMetadataAfterAttachingItToTheSong(): void {
		$user = $this->user();
		$userFolder = $this->createMock(IUserFolder::class);
		$targetFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with(self::UID)->willReturn($userFolder);
		$userFolder->method('get')->with('Noten')->willReturn($targetFolder);
		$userFolder->method('nodeExists')->willReturn(false);

		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/user1/files/Noten/new.pdf');
		$file->method('getContent')->willReturn('%PDF-1.4');
		$file->method('getMTime')->willReturn(1);
		$file->method('putContent')->willReturnCallback(static fn (string $content): int => strlen($content));
		$userFolder->method('newFile')->with('Noten/new.pdf')->willReturn($file);

		$this->databaseFixture['connection']->exec("UPDATE Songs SET Title = 'New Song' WHERE Id = 10");
		$this->databaseFixture['connection']->exec('DELETE FROM Files WHERE Id = 1');
		$songMapper = $this->createSongMapper();

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->expects(self::once())
			->method('uniqueRelativePath')
			->with($userFolder, 'Noten/new.pdf')
			->willReturn('Noten/new.pdf');
		$fileManager->expects(self::once())
			->method('pathOfExistingFile')
			->with($file->getPath(), $user)
			->willReturn(new \OCA\MobileSheetsManager\Service\MobileSheetsPath(
				'/user1/files/Noten/new.pdf',
				'/remote.php/dav/files/user1/Noten/new.pdf',
			));
		$fileManager->expects(self::once())->method('regenerateSongFilePaths');
		$fileManager->expects(self::once())
			->method('syncMetadataIntoFile')
			->with(
				self::callback(static fn ($song): bool => $song->title === 'New Song'),
				self::callback(static fn ($songFile): bool => $songFile->path === '/remote.php/dav/files/user1/Noten/new.pdf'),
				$user,
				$this->databaseFixture['path'],
				self::anything(),
			)
			->willReturn(\OCA\MobileSheetsManager\Service\MobileSheetsFileLogEntry::CODE_METADATA_UPDATED);

		$libraryService = $this->createLibraryServiceForDatabase(
			self::UID,
			1,
			'/Noten/library.db',
			$this->databaseFixture['path'],
		);

		$service = new SongFileService(
			$libraryService,
			$songMapper,
			$fileManager,
			$rootFolder,
			$this->createMock(LoggerInterface::class),
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
		$this->databaseFixture['connection']->exec("UPDATE Files SET Path = '/remote.php/dav/files/user1/Noten/score.pdf' WHERE Id = 1");

		$result = $this->service->detachSongFile($this->user(), 1, 10, 1);

		self::assertSame([], $result['songs'][0]->files);
		self::assertSame('DETACHED_CONTENT', $this->files['Noten/score.pdf']);
		self::assertSame(0, (int)$this->databaseFixture['connection']->query('SELECT COUNT(*) FROM Files WHERE Id = 1')->fetchColumn());
	}

	public function testUploadSongFileFailsWhenAutoPathsDisabledAndFileExists(): void {
		$user = $this->user();
		$userFolder = $this->createMock(IUserFolder::class);
		$targetFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with(self::UID)->willReturn($userFolder);
		$userFolder->method('nodeExists')->with('Noten/existing.pdf')->willReturn(true);

		$libraryMapper = $this->createMock(\OCA\MobileSheetsManager\Db\LibraryMapper::class);
		$libraryMapper->method('findForUser')->with(self::UID, 1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
			'autoPaths' => false,
		]);

		$storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
		$storage->method('getLocalFile')->willReturn($this->databaseFixture['path']);

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/library.db');

		$userFolder->method('get')->willReturnCallback(function (string $path) use ($databaseNode, $targetFolder): Node {
			if ($path === 'Noten/library.db') {
				return $databaseNode;
			}
			if ($path === 'Noten') {
				return $targetFolder;
			}
			throw new \OCP\Files\NotFoundException($path);
		});

		$libraryService = new LibraryService(
			$libraryMapper,
			$this->songMapper,
			$rootFolder,
			$this->logger,
		);

		$service = new SongFileService(
			$libraryService,
			$this->songMapper,
			$this->fileManager,
			$rootFolder,
			$this->logger,
		);

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
		file_put_contents($tmpFile, '%PDF-1.4');

		try {
			$this->expectException(\OCA\MobileSheetsManager\Service\FileAlreadyExistsException::class);
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
		$userFolder->method('nodeExists')->with('Noten/new.pdf')->willReturn(false);

		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/user1/files/Noten/new.pdf');
		$file->method('getContent')->willReturn('%PDF-1.4');
		$file->method('getMTime')->willReturn(1);
		$file->method('putContent')->willReturnCallback(static fn (string $content): int => strlen($content));
		$userFolder->method('newFile')->with('Noten/new.pdf')->willReturn($file);
		$this->databaseFixture['connection']->exec('DELETE FROM Files WHERE Id = 1');

		$libraryMapper = $this->createMock(\OCA\MobileSheetsManager\Db\LibraryMapper::class);
		$libraryMapper->method('findForUser')->with(self::UID, 1)->willReturn([
			'id' => 1,
			'path' => '/Noten/library.db',
			'autoPaths' => false,
		]);

		$storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
		$storage->method('getLocalFile')->willReturn($this->databaseFixture['path']);

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/library.db');

		$userFolder->method('get')->willReturnCallback(function (string $path) use ($databaseNode, $targetFolder): Node {
			if ($path === 'Noten/library.db') {
				return $databaseNode;
			}
			if ($path === 'Noten') {
				return $targetFolder;
			}
			throw new \OCP\Files\NotFoundException($path);
		});

		$libraryService = new LibraryService(
			$libraryMapper,
			$this->songMapper,
			$rootFolder,
			$this->logger,
		);

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->expects(self::never())->method('uniqueRelativePath');
		$fileManager->expects(self::never())->method('regenerateSongFilePaths');
		$fileManager->expects(self::once())
			->method('pathOfExistingFile')
			->with($file->getPath(), $user)
			->willReturn(new \OCA\MobileSheetsManager\Service\MobileSheetsPath(
				'/user1/files/Noten/new.pdf',
				'/remote.php/dav/files/user1/Noten/new.pdf',
			));
		$fileManager->expects(self::once())
			->method('syncMetadataIntoFile')
			->willReturn(\OCA\MobileSheetsManager\Service\MobileSheetsFileLogEntry::CODE_METADATA_UPDATED);

		$service = new SongFileService(
			$libraryService,
			$this->songMapper,
			$fileManager,
			$rootFolder,
			$this->logger,
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
}
