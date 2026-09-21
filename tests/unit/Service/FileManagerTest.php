<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\MobileSheetsArtist;
use OCA\MobileSheetsManager\Db\MobileSheetsBook;
use OCA\MobileSheetsManager\Db\MobileSheetsCollection;
use OCA\MobileSheetsManager\Db\MobileSheetsComposer;
use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\MobileSheetsPathResolver;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers FileManager::regenerateFilePath(), which moves a MobileSheets file to its
 * regenerated location and persists the new WebDAV path in the database.
 *
 * The Nextcloud tree is replaced by an in-memory fake so the tests stay fast and
 * assert the resulting state rather than the individual mock interactions.
 */
final class FileManagerTest extends TestCase {
	private const UID = 'admin';
	private const USER_ROOT = '/admin/files';
	private const LIBRARY_DIR = '/admin/files/Noten';

	/** @var array<string, string> relative path => content */
	private array $files = [];

	/** @var array<string, true> relative path => true */
	private array $folders = [];

	/** @var list<array{from: string, to: string}> recorded move() calls */
	private array $moves = [];

	/** @var list<string> raw targets passed to move() */
	private array $moveTargets = [];

	/** @var list<string> folders created through newFolder() */
	private array $createdFolders = [];

	/** @var list<string> folders removed through delete() */
	private array $deletedFolders = [];

	/** @var list<array{fileId: int, path: string}> recorded updateFile() calls */
	private array $updates = [];

	private Folder $libraryFolder;
	private FileManager $fileManager;
	private IRootFolder $rootFolder;
	private SongMapper $songMapper;
	private IUserFolder $userFolder;

	protected function setUp(): void {
		$this->files = ['Noten/source/SomeFile.pdf' => 'PAYLOAD'];
		$this->folders = ['Noten' => true, 'Noten/source' => true];
		$this->moves = [];
		$this->moveTargets = [];
		$this->createdFolders = [];
		$this->deletedFolders = [];
		$this->updates = [];

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);

		$this->userFolder = $this->fakeFolder('');
		$this->userFolder->method('getPath')->willReturn(self::USER_ROOT);

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with(self::UID)->willReturn($this->userFolder);

		$this->libraryFolder = $this->fakeFolder('Noten');priteprite
		$this->libraryFolder->method('getPath')->willReturn(self::LIBRARY_DIR);

		$this->songMapper = $this->makeRecordingSongMapper();

		$this->fileManager = $this->makeFileManager($this->songMapper);
	}

	/** A FileManager wired to the fake tree and the given SongMapper. */
	private function makeFileManager(SongMapper $songMapper): FileManager {
		return new FileManager(
			$this->createMock(LibraryMapper::class),
			$songMapper,
			$this->rootFolder,
			new MobileSheetsPathResolver($this->rootFolder),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** A SongMapper whose updateFile() calls are recorded instead of executed. */
	private function makeRecordingSongMapper(): SongMapper {
		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('updateFile')->willReturnCallback(
			function (string $databasePath, int $fileId, string $newPath): void {
				$this->updates[] = ['fileId' => $fileId, 'path' => $newPath];
			},
		);

		return $songMapper;
	}

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);

		return $user;
	}

	private function sourceWebdav(string $relative): string {
		return '/remote.php/dav/files/' . self::UID . '/' . $relative;
	}

	/** A song whose files belong in "Jazz/Jazz - Take Five.pdf". */
	private function collectionSong(): MobileSheetsSong {
		return new MobileSheetsSong(
			1,
			'Take Five',
			[],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz')],
			[],
		);
	}

	/** Run the method under test for a file stored at $sourceRelative. */
	private function regenerate(
		MobileSheetsSong $song,
		string $sourceRelative,
		int $fileId = 7,
		string $databasePath = '/tmp/library.db',
	): void {
		$this->fileManager->regenerateFilePath(
			$song,
			new MobileSheetsFile($fileId, $this->sourceWebdav($sourceRelative)),
			$this->user(),
			$this->libraryFolder,
			$databasePath,
		);
	}

	public function testMovesFileToItsRegeneratedLocationAndPersistsTheWebdavPath(): void {
		$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf');

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five.pdf']],
			$this->moves,
		);
		self::assertSame(
			[['fileId' => 7, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five.pdf']],
			$this->updates,
		);
		self::assertArrayHasKey('Noten/Jazz/Jazz - Take Five.pdf', $this->files);
		self::assertSame('PAYLOAD', $this->files['Noten/Jazz/Jazz - Take Five.pdf']);
		self::assertArrayNotHasKey('Noten/source/SomeFile.pdf', $this->files);
	}

	/**
	 * Node::move() resolves against the filesystem root, so a user-relative path
	 * would throw. The fake node rejects anything outside /admin/files.
	 */
	public function testPassesTheFullInternalPathToMove(): void {
		$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf');

		self::assertSame(
			['/admin/files/Noten/Jazz/Jazz - Take Five.pdf'],
			$this->moveTargets,
			'move() must receive the full internal path, not a user-relative one',
		);
	}

	public function testDoesNothingWhenTheFileIsAlreadyAtItsTargetPath(): void {
		$this->files = ['Noten/Jazz/Jazz - Take Five.pdf' => 'PAYLOAD'];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true];

		$this->regenerate($this->collectionSong(), 'Noten/Jazz/Jazz - Take Five.pdf');

		self::assertSame([], $this->moves, 'must not move a file that is already in place');
		self::assertSame([], $this->updates, 'must not rewrite the database when nothing changed');
		self::assertSame('PAYLOAD', $this->files['Noten/Jazz/Jazz - Take Five.pdf']);
	}

	public function testThrowsAndChangesNothingWhenTheTargetAlreadyExists(): void {
		$this->files = [
			'Noten/source/SomeFile.pdf' => 'PAYLOAD',
			'Noten/Jazz/Jazz - Take Five.pdf' => 'EXISTING',
		];
		$this->folders = ['Noten' => true, 'Noten/source' => true, 'Noten/Jazz' => true];

		try {
			$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf');
			self::fail('Expected a RuntimeException because the target already exists');
		} catch (\RuntimeException $exception) {
			self::assertStringContainsString('already exists', $exception->getMessage());
		}

		self::assertSame([], $this->moves, 'nothing may be moved when the target exists');
		self::assertSame([], $this->updates, 'the database must stay untouched');
		self::assertSame('PAYLOAD', $this->files['Noten/source/SomeFile.pdf'], 'the source must survive');
		self::assertSame('EXISTING', $this->files['Noten/Jazz/Jazz - Take Five.pdf'], 'the target must not be overwritten');
	}

	public function testThrowsWhenTheSourceFileDoesNotExist(): void {
		$this->files = [];
		$this->folders = ['Noten' => true, 'Noten/source' => true];

		try {
			$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf');
			self::fail('Expected a RuntimeException because the source is missing');
		} catch (\RuntimeException $exception) {
			self::assertStringContainsString('does not exist', $exception->getMessage());
		}

		self::assertSame([], $this->moves);
		self::assertSame([], $this->updates);
		self::assertArrayNotHasKey('Noten/Jazz/Jazz - Take Five.pdf', $this->files);
	}

	public function testThrowsWhenTheStoredPathBelongsToAnotherUser(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/not below/');

		$this->fileManager->regenerateFilePath(
			$this->collectionSong(),
			new MobileSheetsFile(7, '/remote.php/dav/files/cwick/Familie/Noten/x.pdf'),
			$this->user(),
			$this->libraryFolder,
			'/tmp/library.db',
		);
	}

	public function testThrowsWhenTheSongHasNoComposerArtistOrCollection(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('No composer, artist, or collection');

		$this->regenerate(new MobileSheetsSong(2, 'Untitled'), 'Noten/source/SomeFile.pdf');
	}

	public function testCreatesTheMissingTargetFolderChain(): void {
		$this->files = ['Noten/deep/nested/SomeFile.pdf' => 'PAYLOAD'];
		$this->folders = ['Noten' => true, 'Noten/deep' => true, 'Noten/deep/nested' => true];

		$song = new MobileSheetsSong(
			3,
			'Blue',
			[],
			[new MobileSheetsComposer(10, 'Miles')],
			[new MobileSheetsBook(100, 'Real Book')],
			[],
			[],
		);

		$this->regenerate($song, 'Noten/deep/nested/SomeFile.pdf');

		self::assertSame(
			['Noten/Miles', 'Noten/Miles/Miles - Real Book'],
			$this->createdFolders,
			'the target folder chain must be created one level at a time',
		);
		self::assertArrayHasKey('Noten/Miles/Miles - Real Book/Miles - Real Book - Blue.pdf', $this->files);
	}

	public function testRemovesEmptySourceFoldersUpToTheLibraryFolder(): void {
		$this->files = ['Noten/A/B/C/SomeFile.pdf' => 'PAYLOAD'];
		$this->folders = [
			'Noten' => true,
			'Noten/A' => true,
			'Noten/A/B' => true,
			'Noten/A/B/C' => true,
		];

		$this->regenerate($this->collectionSong(), 'Noten/A/B/C/SomeFile.pdf');

		self::assertSame(
			['Noten/A/B/C', 'Noten/A/B', 'Noten/A'],
			$this->deletedFolders,
			'every emptied folder on the way up must be removed',
		);
		self::assertArrayHasKey('Noten', $this->folders, 'the library folder itself must never be removed');
	}

	public function testKeepsSourceFoldersThatStillContainOtherFiles(): void {
		$this->files = [
			'Noten/A/B/C/SomeFile.pdf' => 'PAYLOAD',
			'Noten/A/B/keep.pdf' => 'KEEP',
		];
		$this->folders = [
			'Noten' => true,
			'Noten/A' => true,
			'Noten/A/B' => true,
			'Noten/A/B/C' => true,
		];

		$this->regenerate($this->collectionSong(), 'Noten/A/B/C/SomeFile.pdf');

		self::assertSame(['Noten/A/B/C'], $this->deletedFolders, 'the walk must stop at the non-empty folder');
		self::assertArrayHasKey('Noten/A/B', $this->folders);
		self::assertArrayHasKey('Noten/A', $this->folders);
		self::assertSame('KEEP', $this->files['Noten/A/B/keep.pdf']);
	}

	public function testNeverRemovesAnythingWhenTheMoveIsRefused(): void {
		$this->files = [
			'Noten/A/B/SomeFile.pdf' => 'PAYLOAD',
			'Noten/Jazz/Jazz - Take Five.pdf' => 'EXISTING',
		];
		$this->folders = ['Noten' => true, 'Noten/A' => true, 'Noten/A/B' => true, 'Noten/Jazz' => true];

		try {
			$this->regenerate($this->collectionSong(), 'Noten/A/B/SomeFile.pdf');
			self::fail('Expected a RuntimeException because the target already exists');
		} catch (\RuntimeException) {
			// expected
		}

		self::assertSame([], $this->deletedFolders, 'a refused move must not tidy up folders');
		self::assertArrayHasKey('Noten/A/B', $this->folders);
		self::assertArrayHasKey('Noten/A', $this->folders);
	}

	public function testRevertsTheMoveWhenTheDatabaseUpdateFails(): void {
		$failing = $this->createMock(SongMapper::class);
		$failing->method('updateFile')->willThrowException(new \RuntimeException('database gone'));
		$this->fileManager = $this->makeFileManager($failing);

		try {
			$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf');
			self::fail('Expected a RuntimeException because the database update failed');
		} catch (\RuntimeException $exception) {
			self::assertStringContainsString('reverted', $exception->getMessage());
		}

		self::assertSame(
			[
				['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five.pdf'],
				['from' => 'Noten/Jazz/Jazz - Take Five.pdf', 'to' => 'Noten/source/SomeFile.pdf'],
			],
			$this->moves,
			'the move must be undone',
		);
		self::assertSame('PAYLOAD', $this->files['Noten/source/SomeFile.pdf'], 'the file must be back at its source');
		self::assertArrayNotHasKey('Noten/Jazz/Jazz - Take Five.pdf', $this->files);
		self::assertSame(['Noten/Jazz'], $this->deletedFolders, 'folders created for the aborted move must be removed');
		self::assertArrayHasKey('Noten/source', $this->folders, 'the source folder must survive for the revert');
	}

	public function testFallsBackToTheArtistWhenThereIsNoComposer(): void {
		$song = new MobileSheetsSong(
			4,
			'Shape of You',
			[],
			[],
			[],
			[],
			[new MobileSheetsArtist(20, 'Ed Sheeran')],
		);

		$this->regenerate($song, 'Noten/source/SomeFile.pdf');

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Ed Sheeran/Ed Sheeran - Shape of You.pdf']],
			$this->moves,
		);
		self::assertSame(
			[['fileId' => 7, 'path' => '/remote.php/dav/files/admin/Noten/Ed Sheeran/Ed Sheeran - Shape of You.pdf']],
			$this->updates,
		);
	}

	/**
	 * The public entry point: resolve the library, read its songs and regenerate
	 * every file they reference.
	 */
	public function testRegenerateFilePathsProcessesEverySongFile(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn('/tmp/mobilesheets.db');

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');
		$databaseNode->method('getParent')->willReturn($this->libraryFolder);

		// Resolves the library node, otherwise behaves like the fake tree.
		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn(self::USER_ROOT);
		$userFolder->method('get')->willReturnCallback(
			fn (string $path) => $path === 'Noten/mobilesheets.db' ? $databaseNode : $this->userFolder->get($path),
		);
		$userFolder->method('nodeExists')->willReturnCallback(
			fn (string $path): bool => $this->userFolder->nodeExists($path),
		);
		$userFolder->method('newFolder')->willReturnCallback(
			fn (string $path): Folder => $this->userFolder->newFolder($path),
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with(self::UID)->willReturn($userFolder);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findById')->with(1)->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db']);

		$this->songMapper->method('findSongs')->with('/tmp/mobilesheets.db')->willReturn([
			new MobileSheetsSong(
				1,
				'Take Five',
				[new MobileSheetsFile(11, $this->sourceWebdav('Noten/source/SomeFile.pdf'))],
				[],
				[],
				[new MobileSheetsCollection(1, 'Jazz')],
				[],
			),
		]);

		$fileManager = new FileManager(
			$libraryMapper,
			$this->songMapper,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$this->createMock(LoggerInterface::class),
		);

		$fileManager->regenerateFilePaths(1, $this->user());

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five.pdf']],
			$this->moves,
		);
		self::assertSame(
			[['fileId' => 11, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five.pdf']],
			$this->updates,
		);
	}

	public function testRegenerateFilePathsThrowsWhenTheLibraryIsUnknown(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findById')->with(99)->willReturn(null);

		$fileManager = new FileManager(
			$libraryMapper,
			$this->songMapper,
			$this->rootFolder,
			new MobileSheetsPathResolver($this->rootFolder),
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Library with ID 99 not found');

		$fileManager->regenerateFilePaths(99, $this->user());
	}

	public function testNeverRemovesFoldersOutsideTheLibraryFolder(): void {
		// A file stored outside the library: walking up from its source must not
		// escape the library folder, otherwise it would delete unrelated folders.
		$this->files = ['elsewhere/deep/SomeFile.pdf' => 'PAYLOAD'];
		$this->folders = ['Noten' => true, 'elsewhere' => true, 'elsewhere/deep' => true];

		$this->regenerate($this->collectionSong(), 'elsewhere/deep/SomeFile.pdf');

		self::assertSame([], $this->deletedFolders, 'the walk must stop at the library folder boundary');
		self::assertArrayHasKey('elsewhere/deep', $this->folders);
		self::assertArrayHasKey('elsewhere', $this->folders);
		self::assertArrayHasKey('Noten/Jazz/Jazz - Take Five.pdf', $this->files, 'the file must still be moved');
	}

	/**
	 * Build a Folder mock backed by the in-memory tree.
	 *
	 * IUserFolder is used for every folder because it extends Folder and is also
	 * what IRootFolder::getUserFolder() declares, so one fake serves both.
	 *
	 * @param string $relative folder path relative to the user folder, '' for the root
	 */
	private function fakeFolder(string $relative): IUserFolder {
		$folder = $this->createMock(IUserFolder::class);

		$folder->method('nodeExists')->willReturnCallback(
			fn (string $path): bool => isset($this->files[$path]) || isset($this->folders[$path]),
		);

		$folder->method('get')->willReturnCallback(function (string $path) {
			if (isset($this->folders[$path])) {
				return $this->fakeFolder($path);
			}
			if (isset($this->files[$path])) {
				return $this->fakeFileNode($path);
			}

			throw new \OCP\Files\NotFoundException($path);
		});

		$folder->method('newFolder')->willReturnCallback(function (string $path): Folder {
			$this->folders[$path] = true;
			$this->createdFolders[] = $path;

			return $this->fakeFolder($path);
		});

		$folder->method('getDirectoryListing')->willReturnCallback(
			function () use ($relative): array {
				$prefix = $relative === '' ? '' : $relative . '/';
				$children = [];
				foreach (array_keys($this->files + $this->folders) as $path) {
					if ($prefix === '' ? true : str_starts_with($path, $prefix)) {
						$rest = substr($path, strlen($prefix));
						if ($rest !== '' && !str_contains($rest, '/')) {
							$children[] = $this->fakeFileNode($path);
						}
					}
				}

				return $children;
			},
		);

		$folder->method('delete')->willReturnCallback(function () use ($relative): void {
			unset($this->folders[$relative]);
			$this->deletedFolders[] = $relative;
		});

		return $folder;
	}

	/** Build a file Node mock that records and applies move() calls. */
	private function fakeFileNode(string $relative): Node {
		$node = $this->createMock(Node::class);

		$node->method('move')->willReturnCallback(function (string $targetInternal) use ($relative): Node {
			$this->moveTargets[] = $targetInternal;

			$prefix = self::USER_ROOT . '/';
			if (!str_starts_with($targetInternal, $prefix)) {
				throw new \OCP\Files\NotPermittedException('Target outside the user folder: ' . $targetInternal);
			}
			$targetRelative = substr($targetInternal, strlen($prefix));

			// Mirror Nextcloud: the target's parent folder has to exist already.
			$parent = dirname($targetRelative);
			if ($parent !== '.' && !isset($this->folders[$parent])) {
				throw new \OCP\Files\NotFoundException($parent);
			}

			$this->moves[] = ['from' => $relative, 'to' => $targetRelative];
			$this->files[$targetRelative] = $this->files[$relative];
			unset($this->files[$relative]);

			return $this->fakeFileNode($targetRelative);
		});

		return $node;
	}
}