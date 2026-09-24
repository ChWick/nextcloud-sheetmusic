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
use OCA\MobileSheetsManager\Service\MobileSheetsHashCodesFile;
use OCA\MobileSheetsManager\Service\MobileSheetsPathResolver;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function OCA\MobileSheetsManager\Service\fileIndexOf;
use function OCA\MobileSheetsManager\Service\fileIndexPrefix;

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

	/** @var list<string> temporary hash codes files to remove after each test */
	private array $temporaryFiles = [];

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
		$this->temporaryFiles = [];
		$this->createdFolders = [];
		$this->deletedFolders = [];
		$this->updates = [];

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);

		$this->userFolder = $this->fakeFolder('');
		$this->userFolder->method('getPath')->willReturn(self::USER_ROOT);

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with(self::UID)->willReturn($this->userFolder);

		$this->libraryFolder = $this->fakeFolder('Noten');
		$this->libraryFolder->method('getPath')->willReturn(self::LIBRARY_DIR);

		$this->songMapper = $this->makeRecordingSongMapper();

		$this->fileManager = $this->makeFileManager($this->songMapper);
	}

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		$this->temporaryFiles = [];
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

	/**
	 * Write a temporary hash codes file in the MobileSheets format.
	 *
	 * @param list<array{0: string, 1: string, 2: string, 3: string}> $entries
	 * @return string path of the written file
	 */
	private function writeHashCodesFile(array $entries): string {
		$lines = [];
		foreach ($entries as $entry) {
			foreach ($entry as $line) {
				$lines[] = $line;
			}
		}

		$path = tempnam(sys_get_temp_dir(), 'msm-hash-');
		self::assertNotFalse($path);
		file_put_contents($path, implode("\n", $lines));
		$this->temporaryFiles[] = $path;

		return $path;
	}

	/** Run the method under test for a file stored at $sourceRelative. */
	private function regenerate(
		MobileSheetsSong $song,
		string $sourceRelative,
		int $fileId = 7,
		string $databasePath = '/tmp/library.db',
		?MobileSheetsHashCodesFile $hashCodes = null,
	): void {
		$this->fileManager->regenerateFilePath(
			$song,
			new MobileSheetsFile($fileId, $this->sourceWebdav($sourceRelative)),
			$this->user(),
			$this->libraryFolder,
			$databasePath,
			$hashCodes,
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

	/**
	 * The naming rules are built from composer, book, collection and title, so two songs
	 * that share a title map onto the same target. The file of the other song must
	 * neither be replaced nor shared, so the name gets a counter.
	 */
	public function testMovesToAUniqueNameWhenTheTargetAlreadyExists(): void {
		$this->files = [
			'Noten/source/SomeFile.pdf' => 'PAYLOAD',
			'Noten/Jazz/Jazz - Take Five.pdf' => 'EXISTING',
		];
		$this->folders = ['Noten' => true, 'Noten/source' => true, 'Noten/Jazz' => true];

		$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf');

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five (2).pdf']],
			$this->moves,
		);
		self::assertSame(
			[['fileId' => 7, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five (2).pdf']],
			$this->updates,
		);
		self::assertSame('EXISTING', $this->files['Noten/Jazz/Jazz - Take Five.pdf'], 'the other song\'s file must survive');
		self::assertSame('PAYLOAD', $this->files['Noten/Jazz/Jazz - Take Five (2).pdf']);
		self::assertArrayNotHasKey('Noten/source/SomeFile.pdf', $this->files);
	}

	public function testCountsUpUntilItFindsAFreeName(): void {
		$this->files = [
			'Noten/source/SomeFile.pdf' => 'PAYLOAD',
			'Noten/Jazz/Jazz - Take Five.pdf' => 'FIRST',
			'Noten/Jazz/Jazz - Take Five (2).pdf' => 'SECOND',
			'Noten/Jazz/Jazz - Take Five (3).pdf' => 'THIRD',
		];
		$this->folders = ['Noten' => true, 'Noten/source' => true, 'Noten/Jazz' => true];

		$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf');

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five (4).pdf']],
			$this->moves,
		);
		self::assertSame('FIRST', $this->files['Noten/Jazz/Jazz - Take Five.pdf']);
		self::assertSame('SECOND', $this->files['Noten/Jazz/Jazz - Take Five (2).pdf']);
		self::assertSame('THIRD', $this->files['Noten/Jazz/Jazz - Take Five (3).pdf']);
		self::assertSame('PAYLOAD', $this->files['Noten/Jazz/Jazz - Take Five (4).pdf']);
	}

	/**
	 * A file that already carries the counter this run would produce has to stay where it
	 * is, otherwise every run of "regenerate file paths" would rename it again.
	 */
	public function testKeepsAFileThatAlreadyCarriesTheCounter(): void {
		$this->files = [
			'Noten/Jazz/Jazz - Take Five.pdf' => 'EXISTING',
			'Noten/Jazz/Jazz - Take Five (2).pdf' => 'PAYLOAD',
		];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true];

		$this->regenerate($this->collectionSong(), 'Noten/Jazz/Jazz - Take Five (2).pdf');

		self::assertSame([], $this->moves, 'a file that already has the unique name must not be renamed');
		self::assertSame([], $this->updates);
		self::assertSame('PAYLOAD', $this->files['Noten/Jazz/Jazz - Take Five (2).pdf']);
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

	/**
	 * A target name being taken is no reason to stop: the file gets a unique name and the
	 * source folders are still tidied up afterwards.
	 */
	public function testTidiesUpSourceFoldersWhenTheTargetNameWasTaken(): void {
		$this->files = [
			'Noten/A/B/SomeFile.pdf' => 'PAYLOAD',
			'Noten/Jazz/Jazz - Take Five.pdf' => 'EXISTING',
		];
		$this->folders = ['Noten' => true, 'Noten/A' => true, 'Noten/A/B' => true, 'Noten/Jazz' => true];

		$this->regenerate($this->collectionSong(), 'Noten/A/B/SomeFile.pdf');

		self::assertSame(
			[['from' => 'Noten/A/B/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five (2).pdf']],
			$this->moves,
		);
		self::assertSame(['Noten/A/B', 'Noten/A'], $this->deletedFolders, 'deepest first');
		self::assertArrayNotHasKey('Noten/A/B', $this->folders);
		self::assertArrayNotHasKey('Noten/A', $this->folders);
		self::assertSame('EXISTING', $this->files['Noten/Jazz/Jazz - Take Five.pdf']);
	}

	public function testDoesNotNumberASingleFileSong(): void {
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[new MobileSheetsFile(11, $this->sourceWebdav('Noten/source/SomeFile.pdf'))],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz')],
			[],
		);

		$this->regenerate($song, 'Noten/source/SomeFile.pdf', 11);

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five.pdf']],
			$this->moves,
		);
	}

	public function testNumbersEachFileOfAMultiFileSongAndKeepsThemApart(): void {
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[
				new MobileSheetsFile(11, $this->sourceWebdav('Noten/source/first.pdf')),
				new MobileSheetsFile(12, $this->sourceWebdav('Noten/source/second.pdf')),
			],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz')],
			[],
		);
		$this->files = [
			'Noten/source/first.pdf' => 'FIRST',
			'Noten/source/second.pdf' => 'SECOND',
		];

		$this->regenerate($song, 'Noten/source/first.pdf', 11);
		$this->regenerate($song, 'Noten/source/second.pdf', 12);

		// The song's files would otherwise all map onto the same name.
		self::assertSame([
			['from' => 'Noten/source/first.pdf', 'to' => 'Noten/Jazz/001 Jazz - Take Five.pdf'],
			['from' => 'Noten/source/second.pdf', 'to' => 'Noten/Jazz/002 Jazz - Take Five.pdf'],
		], $this->moves);

		self::assertSame([
			['fileId' => 11, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/001 Jazz - Take Five.pdf'],
			['fileId' => 12, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/002 Jazz - Take Five.pdf'],
		], $this->updates);

		// The prefix belongs on the file name, not on a folder.
		self::assertSame(['Noten/Jazz'], $this->createdFolders);
		self::assertSame('FIRST', $this->files['Noten/Jazz/001 Jazz - Take Five.pdf']);
		self::assertSame('SECOND', $this->files['Noten/Jazz/002 Jazz - Take Five.pdf']);
	}

	public function testLeavesAFileUnnumberedWhenItIsNotPartOfTheSong(): void {
		// A file that is not listed on the song cannot be numbered; leaving it
		// unprefixed is safe because every listed file gets its own index.
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[
				new MobileSheetsFile(11, $this->sourceWebdav('Noten/source/other.pdf')),
				new MobileSheetsFile(12, $this->sourceWebdav('Noten/source/another.pdf')),
			],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz')],
			[],
		);

		$this->regenerate($song, 'Noten/source/SomeFile.pdf', 99);

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five.pdf']],
			$this->moves,
		);
	}

	public function testNumberPrefixIsZeroPaddedAndSurvivesMoreThanNineFiles(): void {
		$files = [];
		foreach (range(1, 12) as $position) {
			$files[] = new MobileSheetsFile(100 + $position, $this->sourceWebdav("Noten/source/f$position.pdf"));
		}
		$song = new MobileSheetsSong(1, 'Take Five', $files, [], [], [new MobileSheetsCollection(1, 'Jazz')], []);

		self::assertSame('001 ', fileIndexPrefix(fileIndexOf($song, $files[0]), count($files)));
		self::assertSame('009 ', fileIndexPrefix(fileIndexOf($song, $files[8]), count($files)));
		self::assertSame('010 ', fileIndexPrefix(fileIndexOf($song, $files[9]), count($files)));
		self::assertSame('012 ', fileIndexPrefix(fileIndexOf($song, $files[11]), count($files)));

		// Unused for a single-file song, and when the file is unknown.
		self::assertSame('', fileIndexPrefix(1, 1));
		self::assertSame('', fileIndexPrefix(1, 0));
		self::assertSame('', fileIndexPrefix(null, 5));
	}

	public function testNumbersFilesOfAMultiFileSongWithAComposerAndBook(): void {
		$song = new MobileSheetsSong(
			2,
			'Blue',
			[
				new MobileSheetsFile(21, $this->sourceWebdav('Noten/source/a.pdf')),
				new MobileSheetsFile(22, $this->sourceWebdav('Noten/source/b.pdf')),
			],
			[new MobileSheetsComposer(10, 'Miles')],
			[new MobileSheetsBook(100, 'Real Book')],
			[],
			[],
		);
		$this->files = [
			'Noten/source/a.pdf' => 'A',
			'Noten/source/b.pdf' => 'B',
		];

		$this->regenerate($song, 'Noten/source/a.pdf', 21);
		$this->regenerate($song, 'Noten/source/b.pdf', 22);

		// The index goes on the file name, below the composer/book folders.
		self::assertSame([
			['from' => 'Noten/source/a.pdf', 'to' => 'Noten/Miles/Miles - Real Book/001 Miles - Real Book - Blue.pdf'],
			['from' => 'Noten/source/b.pdf', 'to' => 'Noten/Miles/Miles - Real Book/002 Miles - Real Book - Blue.pdf'],
		], $this->moves);

		self::assertSame(
			['Noten/Miles', 'Noten/Miles/Miles - Real Book'],
			$this->createdFolders,
			'only the folders may be created; the index must not become one',
		);
		self::assertSame('A', $this->files['Noten/Miles/Miles - Real Book/001 Miles - Real Book - Blue.pdf']);
		self::assertSame('B', $this->files['Noten/Miles/Miles - Real Book/002 Miles - Real Book - Blue.pdf']);
	}

	public function testUpdatesTheHashCodesFileWhenAFileIsMoved(): void {
		$hashFile = $this->writeHashCodesFile([
			[$this->sourceWebdav('Noten/source/SomeFile.pdf'), '561292453', '1789312275000', '839797'],
			[$this->sourceWebdav('Noten/other.pdf'), '-205429089', '1789249491000', '809049'],
		]);

		$hashCodes = MobileSheetsHashCodesFile::parse($hashFile, $this->createMock(LoggerInterface::class));
		$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf', hashCodes: $hashCodes);

		// FileManager does not save; the caller does. Verify the in-memory edit.
		self::assertTrue($hashCodes->isDirty());
		self::assertFalse($hashCodes->hasPath($this->sourceWebdav('Noten/source/SomeFile.pdf')));

		$target = $this->sourceWebdav('Noten/Jazz/Jazz - Take Five.pdf');
		self::assertTrue($hashCodes->hasPath($target));

		$entries = $hashCodes->entries();
		self::assertSame($target, $entries[0]->path, 'the renamed entry must keep its position');
		self::assertSame('561292453', $entries[0]->hashCode, 'hash must be preserved');
		self::assertSame('1789312275000', $entries[0]->modifiedAt, 'mtime must be preserved');
		self::assertSame('839797', $entries[0]->size, 'size must be preserved');
		self::assertSame(2, count($entries), 'no entry may be added or lost');
		self::assertSame('-205429089', $entries[1]->hashCode, 'unrelated entries must stay untouched');
	}

	/**
	 * The hash codes file keys files by their WebDAV path, so a file that was renamed to a
	 * unique name has to be found there as well.
	 */
	public function testRenamesTheHashCodesEntryToTheUniqueName(): void {
		$hashFile = $this->writeHashCodesFile([
			[$this->sourceWebdav('Noten/A/B/SomeFile.pdf'), '1', '2', '3'],
		]);
		$this->files = [
			'Noten/A/B/SomeFile.pdf' => 'PAYLOAD',
			'Noten/Jazz/Jazz - Take Five.pdf' => 'EXISTING',
		];
		$this->folders = ['Noten' => true, 'Noten/A' => true, 'Noten/A/B' => true, 'Noten/Jazz' => true];

		$hashCodes = MobileSheetsHashCodesFile::parse($hashFile, $this->createMock(LoggerInterface::class));

		$this->regenerate($this->collectionSong(), 'Noten/A/B/SomeFile.pdf', hashCodes: $hashCodes);

		$entries = $hashCodes->entries();

		self::assertTrue($hashCodes->isDirty(), 'the renamed entry has to be persisted');
		self::assertSame(
			$this->sourceWebdav('Noten/Jazz/Jazz - Take Five (2).pdf'),
			$entries[0]->path,
		);
		self::assertSame('1', $entries[0]->hashCode, 'the hash must be carried over');
		self::assertFalse($hashCodes->hasPath($this->sourceWebdav('Noten/A/B/SomeFile.pdf')));
	}

	public function testDoesNotDirtyTheHashCodesFileWhenNothingMoved(): void {
		$alreadyThere = $this->sourceWebdav('Noten/Jazz/Jazz - Take Five.pdf');
		$hashFile = $this->writeHashCodesFile([[$alreadyThere, '1', '2', '3']]);
		$this->files = ['Noten/Jazz/Jazz - Take Five.pdf' => 'PAYLOAD'];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true];

		$hashCodes = MobileSheetsHashCodesFile::parse($hashFile, $this->createMock(LoggerInterface::class));
		$this->regenerate($this->collectionSong(), 'Noten/Jazz/Jazz - Take Five.pdf', hashCodes: $hashCodes);

		self::assertFalse($hashCodes->isDirty(), 'a no-op must not touch the hash codes file');
	}

	public function testSkipsTheHashCodesFileWhenAnEntryIsMissing(): void {
		$hashFile = $this->writeHashCodesFile([
			[$this->sourceWebdav('Noten/unrelated.pdf'), '1', '2', '3'],
		]);

		$hashCodes = MobileSheetsHashCodesFile::parse($hashFile, $this->createMock(LoggerInterface::class));
		$this->regenerate($this->collectionSong(), 'Noten/source/SomeFile.pdf', hashCodes: $hashCodes);

		// The move still happens; there is simply no entry to rename.
		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five.pdf']],
			$this->moves,
		);
		self::assertFalse($hashCodes->isDirty());
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
	 * Both representations of a file that already exists, e.g. one that was just
	 * uploaded into the library folder. The user folder prefix (uid *and* the
	 * "files" segment) has to disappear from the WebDAV path.
	 */
	public function testPathOfExistingFileBuildsTheStoredWebdavPath(): void {
		$path = $this->fileManager->pathOfExistingFile('/admin/files/Noten/T-Pen 2_QG.pdf', $this->user());

		self::assertSame('/admin/files/Noten/T-Pen 2_QG.pdf', $path->internal);
		self::assertSame(
			'/remote.php/dav/files/admin/Noten/T-Pen 2_QG.pdf',
			$path->webdav,
			'the stored path must not repeat the "files" segment of the internal root',
		);
	}

	public function testPathOfExistingFileRejectsPathsOutsideTheUserFolder(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('is not inside the user folder');

		$this->fileManager->pathOfExistingFile('/admin/trashbin/Noten/T-Pen 2_QG.pdf', $this->user());
	}

	public function testPathOfExistingFileRejectsTraversalOutOfTheUserFolder(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->fileManager->pathOfExistingFile('/admin/files/../other/T-Pen 2_QG.pdf', $this->user());
	}

	/** The inverse conversion, needed to delete a file the database points at. */
	public function testPathOfStoredFileBuildsTheNodePath(): void {
		$path = $this->fileManager->pathOfStoredFile(
			'/remote.php/dav/files/admin/Noten/Jazz/002 Jazz - Take Five.pdf',
			$this->user(),
		);

		self::assertNotNull($path);
		self::assertSame('/admin/files/Noten/Jazz/002 Jazz - Take Five.pdf', $path->internal);
		self::assertSame('/remote.php/dav/files/admin/Noten/Jazz/002 Jazz - Take Five.pdf', $path->webdav);
	}

	public function testPathOfStoredFileReturnsNullForAnotherUser(): void {
		self::assertNull(
			$this->fileManager->pathOfStoredFile('/remote.php/dav/files/cwick/Noten/SomeFile.pdf', $this->user()),
			'a library may legitimately reference the files of another user',
		);
	}

	public function testPathOfStoredFileReturnsNullForAPathOutsideTheWebdavRoot(): void {
		self::assertNull($this->fileManager->pathOfStoredFile('/Noten/SomeFile.pdf', $this->user()));
	}

	/** The song is read from the database, so a file that was just added takes part. */
	public function testRegenerateSongFilePathsNumbersANewlyAddedFileLast(): void {
		// One file was already regenerated, a second one was just uploaded next to the
		// library database and recorded with the highest Id.
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[
				new MobileSheetsFile(11, $this->sourceWebdav('Noten/Jazz/Jazz - Take Five.pdf')),
				new MobileSheetsFile(12, $this->sourceWebdav('Noten/uploaded.pdf')),
			],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz')],
			[],
		);
		$this->files = [
			'Noten/Jazz/Jazz - Take Five.pdf' => 'FIRST',
			'Noten/uploaded.pdf' => 'UPLOADED',
		];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true];
		$this->songMapper->method('findSong')->with('/tmp/library.db', 1)->willReturn($song);

		$this->fileManager->regenerateSongFilePaths(1, $this->user(), $this->libraryFolder, '/tmp/library.db');

		// The single file's plain name becomes "001 ..." and the new file joins as
		// "002 ...", so both now follow the rules for multi-file songs.
		self::assertSame([
			['from' => 'Noten/Jazz/Jazz - Take Five.pdf', 'to' => 'Noten/Jazz/001 Jazz - Take Five.pdf'],
			['from' => 'Noten/uploaded.pdf', 'to' => 'Noten/Jazz/002 Jazz - Take Five.pdf'],
		], $this->moves);
		self::assertSame([
			['fileId' => 11, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/001 Jazz - Take Five.pdf'],
			['fileId' => 12, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/002 Jazz - Take Five.pdf'],
		], $this->updates);
		self::assertSame('UPLOADED', $this->files['Noten/Jazz/002 Jazz - Take Five.pdf']);
	}

	public function testRegenerateSongFilePathsUpdatesTheHashCodesFile(): void {
		$source = $this->sourceWebdav('Noten/old/SomeFile.pdf');
		$hashFile = $this->writeHashCodesFile([[$source, '1', '2', '3']]);
		$hashCodes = MobileSheetsHashCodesFile::parse($hashFile, $this->createMock(LoggerInterface::class));

		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[new MobileSheetsFile(11, $source)],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz')],
			[],
		);
		$this->files = ['Noten/old/SomeFile.pdf' => 'PAYLOAD'];
		$this->folders = ['Noten' => true, 'Noten/old' => true];
		$this->songMapper->method('findSong')->willReturn($song);

		$this->fileManager->regenerateSongFilePaths(1, $this->user(), $this->libraryFolder, '/tmp/library.db', $hashCodes);

		self::assertFalse($hashCodes->hasPath($source));
		self::assertTrue($hashCodes->hasPath($this->sourceWebdav('Noten/Jazz/Jazz - Take Five.pdf')));
	}

	/**
	 * A song without a composer, artist or collection has no naming rule, so there is
	 * nowhere to move its files to: the file that was just added stays in the library
	 * folder and nothing else moves either.
	 */
	public function testRegenerateSongFilePathsLeavesFilesAloneWithoutANamingRule(): void {
		$song = new MobileSheetsSong(
			2,
			'Untitled',
			[new MobileSheetsFile(21, $this->sourceWebdav('Noten/T-Pen 2_QG.pdf'))],
		);
		$this->files = ['Noten/T-Pen 2_QG.pdf' => 'PAYLOAD'];
		$this->songMapper->method('findSong')->willReturn($song);

		try {
			$this->fileManager->regenerateSongFilePaths(2, $this->user(), $this->libraryFolder, '/tmp/library.db');
			self::fail('Expected an exception because the song offers no composer, artist or collection');
		} catch (\Exception $exception) {
			self::assertSame('No composer, artist, or collection', $exception->getMessage());
		}

		self::assertSame([], $this->moves, 'the failure has to happen before anything moves');
		self::assertSame([], $this->updates);
		self::assertSame('PAYLOAD', $this->files['Noten/T-Pen 2_QG.pdf']);
	}

	public function testRegenerateSongFilePathsThrowsWhenTheSongIsUnknown(): void {
		$this->songMapper->method('findSong')->with('/tmp/library.db', 99)->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Song with ID 99 not found');

		$this->fileManager->regenerateSongFilePaths(99, $this->user(), $this->libraryFolder, '/tmp/library.db');
	}

	/**
	 * The helper behind every unique name: an occupied path gets a counter before the
	 * extension, so no file ever replaces another one.
	 */
	public function testUniqueRelativePathKeepsAFreeName(): void {
		self::assertSame(
			'Noten/Free.pdf',
			$this->fileManager->uniqueRelativePath($this->userFolder, 'Noten/Free.pdf'),
		);
	}

	public function testUniqueRelativePathCountsUpForTakenNames(): void {
		$this->files = [
			'Noten/Taken.pdf' => 'A',
			'Noten/Taken (2).pdf' => 'B',
		];
		$this->folders = ['Noten' => true];

		self::assertSame(
			'Noten/Taken (3).pdf',
			$this->fileManager->uniqueRelativePath($this->userFolder, 'Noten/Taken.pdf'),
		);
	}

	public function testUniqueRelativePathKeepsTheSubfolderAndExtension(): void {
		$this->files = ['Noten/Jazz/Jazz - Take Five.pdf' => 'A'];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true];

		self::assertSame(
			'Noten/Jazz/Jazz - Take Five (2).pdf',
			$this->fileManager->uniqueRelativePath($this->userFolder, 'Noten/Jazz/Jazz - Take Five.pdf'),
		);
	}

	public function testUniqueRelativePathHandlesNamesWithoutAnExtension(): void {
		$this->files = ['Noten/no-extension' => 'A'];
		$this->folders = ['Noten' => true];

		self::assertSame(
			'Noten/no-extension (2)',
			$this->fileManager->uniqueRelativePath($this->userFolder, 'Noten/no-extension'),
		);
	}

	/** The file being handled may already sit at the target; that is not a collision. */
	public function testUniqueRelativePathTreatsTheKeptPathAsFree(): void {
		$this->files = ['Noten/Taken.pdf' => 'A'];
		$this->folders = ['Noten' => true];

		self::assertSame(
			'Noten/Taken.pdf',
			$this->fileManager->uniqueRelativePath($this->userFolder, 'Noten/Taken.pdf', 'Noten/Taken.pdf'),
		);
		self::assertSame(
			'Noten/Taken.pdf',
			$this->fileManager->uniqueRelativePath($this->userFolder, 'Noten/Taken.pdf', '/Noten/Taken.pdf'),
		);
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