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
use OCA\MobileSheetsManager\Service\MobileSheetsFileLogEntry;
use OCA\MobileSheetsManager\Service\MobileSheetsHashCodesFile;
use OCA\MobileSheetsManager\Service\MobileSheetsPathResolver;
use OCA\MobileSheetsManager\Service\MobileSheetsPdfMetadata;
use OCA\MobileSheetsManager\Service\SyncException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

	/** @var array<string, int> relative path => assigned node id */
	private array $nodeIds = [];

	/** @var array<string, int> webdav path => song id referencing it */
	private array $referencedPaths = [];

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
		$this->nodeIds = [];

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
		$this->referencedPaths = [];
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
		$songMapper->method('isPathReferencedByOtherSong')->willReturnCallback(
			function (string $databasePath, string $filePath, int $songId): bool {
				return isset($this->referencedPaths[$filePath]) && $this->referencedPaths[$filePath] !== $songId;
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

	public function testCreateTargetPathsIncludesKeyInTitleWhenKeyIsPresent(): void {
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[],
			[new MobileSheetsComposer(1, 'Dave Brubeck')],
			[],
			[],
			[],
			[],
			[new \OCA\MobileSheetsManager\Db\MobileSheetsKey(1, 'Ebm')],
		);

		$paths = \OCA\MobileSheetsManager\Service\createTargetPaths($song, '.pdf');

		self::assertCount(2, $paths);
		self::assertSame('Dave Brubeck/Dave Brubeck - Take Five in Ebm.pdf', $paths[0]);
		self::assertSame('Dave Brubeck - Take Five in Ebm.pdf', $paths[1]);
	}

	public function testCreateTargetPathsNormalizesCrossPlatformInvalidNames(): void {
		$song = new MobileSheetsSong(
			1,
			'Song * Name. ',
			[],
			[new MobileSheetsComposer(1, 'Bad/Composer. ')],
			[new MobileSheetsBook(1, 'Book?.')],
		);

		$path = \OCA\MobileSheetsManager\Service\createTargetPath($song, '.pdf');

		self::assertSame('Bad-Composer/Bad-Composer - Book-/Bad-Composer - Book- - Song - Name.pdf', $path);
		foreach (explode('/', $path) as $segment) {
			self::assertDoesNotMatchRegularExpression('/[ .]$/', $segment);
			self::assertDoesNotMatchRegularExpression('/[<>:"\/\\\\|?*\x00-\x1F\x7F]/', $segment);
			self::assertLessThanOrEqual(250, strlen($segment));
		}
	}

	public function testCreateTargetPathsPrefixesWindowsReservedDirectoryNames(): void {
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[],
			[new MobileSheetsComposer(1, 'CON')],
		);

		self::assertSame(
			'_CON/_CON - Take Five.pdf',
			\OCA\MobileSheetsManager\Service\createTargetPath($song, '.pdf'),
		);
	}

	public function testCreateTargetPathsShortensLongNamesWithoutCuttingTheExtension(): void {
		$song = new MobileSheetsSong(
			1,
			str_repeat('é', 150),
			[],
			[new MobileSheetsComposer(1, 'Miles')],
		);

		$path = \OCA\MobileSheetsManager\Service\createTargetPath($song, '.pdf');
		$fileName = basename($path);

		self::assertLessThanOrEqual(250, strlen($fileName));
		self::assertStringEndsWith('.pdf', $fileName);
		self::assertTrue(mb_check_encoding($fileName, 'UTF-8'));
	}

	public function testCreateTargetPathsAvoidsNextcloudReservedUploadSuffixes(): void {
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[new MobileSheetsFile(1, '/remote.php/dav/files/admin/Noten/source/song.part')],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz')],
		);

		$path = \OCA\MobileSheetsManager\Service\createTargetPath($song, '.part');

		self::assertStringEndsWith('Take Five-part', $path);
		self::assertFalse(str_ends_with($path, '.part'));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function hostilePathNameCases(): array {
		return [
			'empty' => [''],
			'one dot' => ['.'],
			'two dots' => ['..'],
			'only dots' => ['.....'],
			'only spaces' => ['     '],
			'trailing spaces and dots' => ["  Song title...   "],
			'leading dots' => ['...Song'],
			'path separators' => ['../folder\\song'],
			'windows reserved characters' => ['a<b>c:d"e/f\\g|h?i*j'],
			'control characters' => ["line\nbreak\tand\x00null\x7F"],
			'windows device name' => ['CON'],
			'windows device name with extension' => ['LPT1.pdf'],
			'windows device name with trailing dot' => ['NUL.'],
			'reserved Nextcloud upload suffix' => ['name.filepart'],
			'reserved Nextcloud part suffix' => ['name.part'],
			'htaccess' => ['.htaccess'],
			'comma and separator-like punctuation' => ['A, B / C'],
			'non-ASCII Latin' => ['Georg Friedrich Händel'],
			'non-Latin scripts' => ['東京の音楽'],
			'emoji and supplementary Unicode' => ['🎼🎹🎻'],
			'combining marks' => ["e\u{0301}"],
			'long ASCII name' => [str_repeat('a', 500)],
			'long multibyte name' => [str_repeat('é🎼', 200)],
			'invalid UTF-8 leading byte' => ["\xC3"],
			'invalid UTF-8 continuation byte' => ["\x80"],
			'invalid UTF-8 mixed with text' => ["title\xFFbroken"],
		];
	}

	#[DataProvider('hostilePathNameCases')]
	public function testCreateTargetPathsHandlesHostileNamesInEveryComponent(string $name): void {
		$cases = [
			'title' => new MobileSheetsSong(1, $name, [], [new MobileSheetsComposer(1, 'Bach')]),
			'composer' => new MobileSheetsSong(1, 'Take Five', [], [new MobileSheetsComposer(1, $name)]),
			'artist fallback' => new MobileSheetsSong(1, 'Take Five', [], [], [], [], [new MobileSheetsArtist(1, $name)]),
			'book' => new MobileSheetsSong(1, 'Take Five', [], [new MobileSheetsComposer(1, 'Bach')], [new MobileSheetsBook(1, $name)]),
			'collection' => new MobileSheetsSong(1, 'Take Five', [], [], [], [new MobileSheetsCollection(1, $name)]),
			'key' => new MobileSheetsSong(1, 'Take Five', [], [new MobileSheetsComposer(1, 'Bach')], [], [], [], [], [new \OCA\MobileSheetsManager\Db\MobileSheetsKey(1, $name)]),
		];

		foreach ($cases as $component => $song) {
			try {
				$paths = \OCA\MobileSheetsManager\Service\createTargetPaths($song, '.pdf');
			} catch (\OCA\MobileSheetsManager\Service\NoNamingRuleException $exception) {
				// An empty composer, artist, and collection is intentionally not a
				// naming rule; callers already handle this domain exception.
				self::assertSame('', $name, sprintf('Unexpected missing naming rule for %s', $component));
				self::assertContains($component, ['composer', 'artist fallback', 'collection']);
				continue;
			}

			self::assertNotEmpty($paths, sprintf('No path was generated for %s', $component));
			foreach ($paths as $path) {
				self::assertNotSame('', $path, sprintf('Empty path generated for %s', $component));
				foreach (explode('/', $path) as $segment) {
					self::assertNotSame('', $segment, sprintf('Empty path segment generated for %s', $component));
					self::assertNotContains($segment, ['.', '..'], sprintf('Unsafe dot segment generated for %s', $component));
					self::assertDoesNotMatchRegularExpression('/[ .]$/', $segment, sprintf('Trailing dot/space in %s', $component));
					self::assertDoesNotMatchRegularExpression('/[<>:"\/\\\\|?*\x00-\x1F\x7F]/', $segment, sprintf('Invalid path character in %s', $component));
					self::assertLessThanOrEqual(250, strlen($segment), sprintf('Overlong segment generated for %s', $component));
					self::assertTrue(mb_check_encoding($segment, 'UTF-8'), sprintf('Invalid UTF-8 generated for %s', $component));
				}
			}
		}
	}

	public function testCreateTargetPathsHandlesSeveralBrokenNamesTogether(): void {
		$song = new MobileSheetsSong(
			1,
			"...\xFF Song. ",
			[],
			[new MobileSheetsComposer(1, "CON\x00")],
			[new MobileSheetsBook(1, str_repeat('é', 200)), new MobileSheetsBook(2, "\xFF")],
			[new MobileSheetsCollection(1, '...'), new MobileSheetsCollection(2, "\xFF")],
			[new MobileSheetsArtist(1, 'Artist/Name')],
			[],
			[new \OCA\MobileSheetsManager\Db\MobileSheetsKey(1, "Key\tName")],
		);

		$paths = \OCA\MobileSheetsManager\Service\createTargetPaths($song, '.pdf');

		self::assertNotEmpty($paths);
		foreach ($paths as $path) {
			foreach (explode('/', $path) as $segment) {
				self::assertNotSame('', $segment);
				self::assertNotContains($segment, ['.', '..']);
				self::assertDoesNotMatchRegularExpression('/[ .]$/', $segment);
				self::assertLessThanOrEqual(250, strlen($segment));
				self::assertTrue(mb_check_encoding($segment, 'UTF-8'));
			}
		}

		$this->regenerate($song, 'Noten/source/SomeFile.pdf');
		self::assertCount(1, $this->moves);
		self::assertContains(substr($this->moves[0]['to'], strlen('Noten/')), $paths);
		self::assertArrayHasKey($this->moves[0]['to'], $this->files);
	}

	public function testCreateTargetPathsHandlesEverySingleByteInEveryComponent(): void {
		for ($byte = 0; $byte <= 0xFF; $byte++) {
			$name = chr($byte);
			$cases = [
				new MobileSheetsSong(1, $name, [], [new MobileSheetsComposer(1, 'Composer')]),
				new MobileSheetsSong(1, 'Title', [], [new MobileSheetsComposer(1, $name)]),
				new MobileSheetsSong(1, 'Title', [], [], [], [], [new MobileSheetsArtist(1, $name)]),
				new MobileSheetsSong(1, 'Title', [], [new MobileSheetsComposer(1, 'Composer')], [new MobileSheetsBook(1, $name)]),
				new MobileSheetsSong(1, 'Title', [], [], [], [new MobileSheetsCollection(1, $name)]),
				new MobileSheetsSong(1, 'Title', [], [new MobileSheetsComposer(1, 'Composer')], [], [], [], [], [new \OCA\MobileSheetsManager\Db\MobileSheetsKey(1, $name)]),
			];

			foreach ($cases as $song) {
				$paths = \OCA\MobileSheetsManager\Service\createTargetPaths($song, '.pdf');
				self::assertNotEmpty($paths, sprintf('No path generated for byte 0x%02X', $byte));
				foreach ($paths as $path) {
					foreach (explode('/', $path) as $segment) {
						self::assertNotSame('', $segment);
						self::assertNotContains($segment, ['.', '..']);
						self::assertDoesNotMatchRegularExpression('/[ .]$/', $segment);
						self::assertDoesNotMatchRegularExpression('/[<>:"\/\\\\|?*\x00-\x1F\x7F]/', $segment);
						self::assertLessThanOrEqual(250, strlen($segment));
						self::assertTrue(mb_check_encoding($segment, 'UTF-8'));
					}
				}
			}
		}
	}

	public function testCreateTargetPathsHandlesBrokenExtensions(): void {
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[],
			[new MobileSheetsComposer(1, 'Bach')],
		);
		$extensions = [
			'',
			'.',
			'..',
			'.pdf',
			'.part',
			'.filepart',
			'.verylong' . str_repeat('x', 300),
			".pdf\x00",
			".\xFF",
			'.音楽',
		];

		foreach ($extensions as $extension) {
			$paths = \OCA\MobileSheetsManager\Service\createTargetPaths($song, $extension);
			self::assertNotEmpty($paths, 'No path generated for extension ' . bin2hex($extension));
			foreach ($paths as $path) {
				foreach (explode('/', $path) as $segment) {
					self::assertNotSame('', $segment);
					self::assertNotContains($segment, ['.', '..']);
					self::assertDoesNotMatchRegularExpression('/[ .]$/', $segment);
					self::assertLessThanOrEqual(250, strlen($segment));
					self::assertTrue(mb_check_encoding($segment, 'UTF-8'));
				}
			}
		}
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

	public function testMovesToNormalizedGeneratedPathBeforeCreatingFolders(): void {
		$song = new MobileSheetsSong(
			1,
			'Take Five',
			[],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz.')],
		);

		$this->regenerate($song, 'Noten/source/SomeFile.pdf');

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five.pdf']],
			$this->moves,
		);
		self::assertSame(['Noten/Jazz'], $this->createdFolders);
	}

	public function testDoesNothingWhenTheFileIsAlreadyAtItsTargetPath(): void {
		$this->files = ['Noten/Jazz/Jazz - Take Five.pdf' => 'PAYLOAD'];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true];

		$this->regenerate($this->collectionSong(), 'Noten/Jazz/Jazz - Take Five.pdf');

		self::assertSame([], $this->moves, 'must not move a file that is already in place');
		self::assertSame([], $this->updates, 'must not rewrite the database when nothing changed');
		self::assertSame('PAYLOAD', $this->files['Noten/Jazz/Jazz - Take Five.pdf']);
	}

	public function testDuplicatesSharedFileWhenAlreadyAtTargetPath(): void {
		$this->files = ['Noten/Jazz/Jazz - Take Five.pdf' => 'PAYLOAD'];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true];
		$webdavPath = $this->sourceWebdav('Noten/Jazz/Jazz - Take Five.pdf');
		// Mark path as referenced by song ID 99 (different from song 1)
		$this->referencedPaths[$webdavPath] = 99;

		$this->regenerate($this->collectionSong(), 'Noten/Jazz/Jazz - Take Five.pdf');

		self::assertArrayHasKey('Noten/Jazz/Jazz - Take Five (2).pdf', $this->files);
		self::assertSame(
			[['fileId' => 7, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five (2).pdf']],
			$this->updates,
		);
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

	public function testLinksToExistingTargetFileWhenSourceIsMissing(): void {
		$this->files = [
			'Noten/Jazz/Jazz - Take Five.pdf' => 'TARGET_PAYLOAD',
		];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true, 'Noten/source' => true];

		$entry = $this->fileManager->regenerateFilePath(
			$this->collectionSong(),
			new MobileSheetsFile(7, '/remote.php/dav/files/admin/Noten/source/MissingOldFile.pdf'),
			$this->user(),
			$this->libraryFolder,
			'/tmp/library.db',
		);

		self::assertSame([], $this->moves);
		self::assertCount(1, $this->updates);
		self::assertSame('/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five.pdf', $this->updates[0]['path']);
		self::assertStringContainsString('Linked to existing target file', $entry->message);
	}

	public function testDuplicatesFileWhenTargetIsReferencedByAnotherSong(): void {
		$this->files = [
			'Noten/Jazz/Jazz - Take Five.pdf' => 'TARGET_PAYLOAD',
		];
		$this->folders = ['Noten' => true, 'Noten/Jazz' => true, 'Noten/source' => true];
		// Target path is referenced by song 10 (different song ID than our test song)
		$this->referencedPaths['/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five.pdf'] = 10;

		$entry = $this->fileManager->regenerateFilePath(
			$this->collectionSong(),
			new MobileSheetsFile(7, '/remote.php/dav/files/admin/Noten/source/MissingOldFile.pdf'),
			$this->user(),
			$this->libraryFolder,
			'/tmp/library.db',
		);

		self::assertCount(1, $this->updates);
		self::assertSame('/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five (2).pdf', $this->updates[0]['path']);
		self::assertStringContainsString('Copied file for separate song', $entry->message);
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

	public function testSortsAndJoinsMultipleComposersAlphabetically(): void {
		$song = new MobileSheetsSong(
			10,
			'Postludium',
			[],
			[new MobileSheetsComposer(2, 'W.A. Mozart'), new MobileSheetsComposer(1, 'J.S. Bach')],
			[],
			[new MobileSheetsCollection(1, 'Short'), new MobileSheetsCollection(2, 'Longer Collection Name')],
			[],
		);

		$this->regenerate($song, 'Noten/source/SomeFile.pdf');

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/J.S. Bach, W.A. Mozart/J.S. Bach, W.A. Mozart - Longer Collection Name/J.S. Bach, W.A. Mozart - Longer Collection Name - Postludium.pdf']],
			$this->moves,
		);
	}

	public function testSelectsLongestCollectionNameForStableFilePath(): void {
		$song = new MobileSheetsSong(
			11,
			'Postludium',
			[],
			[new MobileSheetsComposer(1, 'Moritz Brosig')],
			[],
			[
				new MobileSheetsCollection(1, 'Freie Orgelmusik aus vier Jahrhunderten'),
				new MobileSheetsCollection(2, 'Freie Orgelmusik aus vier Jahrhunderten - Grün'),
			],
			[],
		);

		$this->regenerate($song, 'Noten/source/SomeFile.pdf');

		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Moritz Brosig/Moritz Brosig - Freie Orgelmusik aus vier Jahrhunderten - Grün/Moritz Brosig - Freie Orgelmusik aus vier Jahrhunderten - Grün - Postludium.pdf']],
			$this->moves,
		);
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

	public function testDoesNotPrependNumberPrefixIfFileExtensionsDiffer(): void {
		$song = new MobileSheetsSong(
			3,
			'Blue',
			[
				new MobileSheetsFile(31, $this->sourceWebdav('Noten/source/sheet.pdf')),
				new MobileSheetsFile(32, $this->sourceWebdav('Noten/source/audio.mid')),
			],
			[new MobileSheetsComposer(10, 'Miles')],
			[new MobileSheetsBook(100, 'Real Book')],
			[],
			[],
		);
		$this->files = [
			'Noten/source/sheet.pdf' => 'PDF Content',
			'Noten/source/audio.mid' => 'MIDI Content',
		];

		$this->regenerate($song, 'Noten/source/sheet.pdf', 31);
		$this->regenerate($song, 'Noten/source/audio.mid', 32);

		self::assertSame([
			['from' => 'Noten/source/sheet.pdf', 'to' => 'Noten/Miles/Miles - Real Book/Miles - Real Book - Blue.pdf'],
			['from' => 'Noten/source/audio.mid', 'to' => 'Noten/Miles/Miles - Real Book/Miles - Real Book - Blue.mid'],
		], $this->moves);
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
	/**
	 * A FileManager wired for the bulk regenerateFilePaths(): the library node
	 * resolves from the fake tree and findSongs() serves the given songs.
	 *
	 * @param list<MobileSheetsSong> $songs
	 */
	private function makeBulkFileManager(array $songs, ?LoggerInterface $logger = null): FileManager {
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

		$this->songMapper->method('findSongs')->with('/tmp/mobilesheets.db')->willReturn($songs);

		return new FileManager(
			$libraryMapper,
			$this->songMapper,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	public function testRegenerateFilePathsProcessesEverySongFile(): void {
		$fileManager = $this->makeBulkFileManager([
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

	/**
	 * A file that cannot be moved is logged and does not stop the run: the next
	 * song's file is still relocated, and every file the run touched is reported.
	 */
	public function testRegenerateFilePathsLogsEveryFileAndKeepsGoing(): void {
		$fileManager = $this->makeBulkFileManager([
			// Missing on disk, so this one fails...
			new MobileSheetsSong(
				1,
				'Broken',
				[new MobileSheetsFile(11, $this->sourceWebdav('Noten/source/MissingFile.pdf'))],
				[],
				[],
				[new MobileSheetsCollection(1, 'Jazz')],
				[],
			),
			// ...and must not stop this one from being moved.
			new MobileSheetsSong(
				2,
				'Take Five',
				[new MobileSheetsFile(22, $this->sourceWebdav('Noten/source/SomeFile.pdf'))],
				[],
				[],
				[new MobileSheetsCollection(1, 'Jazz')],
				[],
			),
		]);

		$log = $fileManager->regenerateFilePaths(1, $this->user());

		// The run continued: the second file still reached its regenerated path.
		self::assertSame(
			[['from' => 'Noten/source/SomeFile.pdf', 'to' => 'Noten/Jazz/Jazz - Take Five.pdf']],
			$this->moves,
		);
		self::assertSame(
			[['fileId' => 22, 'path' => '/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five.pdf']],
			$this->updates,
		);

		// Every file touched is in the log: the failure with its reason, then the move.
		self::assertCount(2, $log);
		[$failed, $moved] = $log;
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_ERROR, $failed->level);
		self::assertSame(MobileSheetsFileLogEntry::CODE_SOURCE_MISSING, $failed->code);
		self::assertNull($failed->fileId, 'a file that does not exist has no node to link to');
		self::assertSame(1, $failed->songId);
		self::assertSame('Broken', $failed->songTitle);
		self::assertSame($this->sourceWebdav('Noten/source/MissingFile.pdf'), $failed->filePath);
		self::assertStringContainsString('does not exist', $failed->message);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_INFO, $moved->level);
		self::assertSame(MobileSheetsFileLogEntry::CODE_PATH_MOVED, $moved->code);
		self::assertNotNull($moved->fileId, 'the report links to the file where it now lives');
		self::assertSame(2, $moved->songId);
		self::assertStringContainsString('Moved to', $moved->message);
	}

	/**
	 * Expected failures (a song with no naming rule, a missing source) are reported
	 * to the user through the returned list, so they must not clutter the Nextcloud
	 * log with a warning and a stack trace per file.
	 */
	/**
	 * A song with no composer, artist or collection keeps its file name on purpose,
	 * so it is reported as a warning rather than an error.
	 */
	public function testRegenerateFilePathsGradesANoNamingRuleAsAWarning(): void {
		$fileManager = $this->makeBulkFileManager([
			new MobileSheetsSong(
				1,
				'Untitled',
				[new MobileSheetsFile(11, $this->sourceWebdav('Noten/source/SomeFile.pdf'))],
			),
		]);

		$log = $fileManager->regenerateFilePaths(1, $this->user());

		self::assertSame([], $this->moves, 'nothing may move without a naming rule');
		self::assertCount(1, $log);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_WARNING, $log[0]->level);
		self::assertSame(MobileSheetsFileLogEntry::CODE_NO_NAMING_RULE, $log[0]->code);
		self::assertNotNull($log[0]->fileId, 'the file that kept its name is still linkable');
		self::assertStringContainsString('No composer', $log[0]->message);
	}

	public function testRegenerateFilePathsDoesNotLogExpectedFailures(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');

		$fileManager = $this->makeBulkFileManager([
			new MobileSheetsSong(
				1,
				'Broken',
				[new MobileSheetsFile(11, $this->sourceWebdav('Noten/source/MissingFile.pdf'))],
				[],
				[],
				[new MobileSheetsCollection(1, 'Jazz')],
				[],
			),
		], $logger);

		$errors = $fileManager->regenerateFilePaths(1, $this->user());

		self::assertCount(1, $errors);
	}

	/**
	 * The metadata phase of a sync run: syncMetadataIntoFile() brings one
	 * attached file's document properties up to date with its song.
	 */
	public function testSyncMetadataWritesTheDocumentProperties(): void {
		$this->files = ['Noten/song.pdf' => self::minimalPdf()];
		$this->folders = ['Noten' => true];
		$song = $this->collectionSong();

		$code = $this->fileManager->syncMetadataIntoFile(
			$song,
			new MobileSheetsFile(7, $this->sourceWebdav('Noten/song.pdf')),
			$this->user(),
			'/tmp/library.db',
		);

		self::assertSame(MobileSheetsFileLogEntry::CODE_METADATA_UPDATED, $code);
		self::assertSame([
			'title' => 'Take Five',
			'composers' => [],
			'books' => [],
			'collections' => ['Jazz'],
			'artists' => [],
		], MobileSheetsPdfMetadata::prefillFor($this->files['Noten/song.pdf']));
		// The bytes changed, so the file facts have to follow them.
		self::assertSame(
			[['fileId' => 7, 'path' => $this->sourceWebdav('Noten/song.pdf')]],
			$this->updates,
		);
	}

	/** A file that already carries the song's properties must not be written again. */
	public function testSyncMetadataSkipsAFileThatAlreadyMatchesTheSong(): void {
		$song = $this->collectionSong();
		$this->files = [
			'Noten/song.pdf' => MobileSheetsPdfMetadata::withInfo(
				self::minimalPdf(),
				MobileSheetsPdfMetadata::fieldsFor($song),
			),
		];
		$this->folders = ['Noten' => true];

		$code = $this->fileManager->syncMetadataIntoFile(
			$song,
			new MobileSheetsFile(7, $this->sourceWebdav('Noten/song.pdf')),
			$this->user(),
			'/tmp/library.db',
		);

		self::assertSame(MobileSheetsFileLogEntry::CODE_METADATA_CURRENT, $code);
		self::assertSame([], $this->updates, 'unchanged bytes need no database refresh');
	}

	/** Files without document properties are reported as checked, and skipped. */
	public function testSyncMetadataSkipsFilesThatAreNotPdfs(): void {
		$this->files = ['Noten/track.mp3' => 'AUDIO'];
		$this->folders = ['Noten' => true];

		$code = $this->fileManager->syncMetadataIntoFile(
			$this->collectionSong(),
			new MobileSheetsFile(7, $this->sourceWebdav('Noten/track.mp3')),
			$this->user(),
			'/tmp/library.db',
		);

		self::assertSame(MobileSheetsFileLogEntry::CODE_NOT_PDF, $code);
		self::assertSame([], $this->updates);
	}

	/** The database points at a file that is not there: a coded error to link from. */
	public function testSyncMetadataReportsAMissingFile(): void {
		$this->files = [];
		$this->folders = ['Noten' => true];

		self::assertSame(
			MobileSheetsFileLogEntry::CODE_METADATA_FILE_MISSING,
			$this->syncMetadataFailure(new MobileSheetsFile(7, $this->sourceWebdav('Noten/gone.pdf'))),
		);
	}

	/** A stored path of another instance or user cannot be synced from here. */
	public function testSyncMetadataReportsAStoredPathOfAnotherUser(): void {
		self::assertSame(
			MobileSheetsFileLogEntry::CODE_SOURCE_OUTSIDE_LIBRARY,
			$this->syncMetadataFailure(new MobileSheetsFile(7, '/remote.php/dav/files/other/Noten/song.pdf')),
		);
	}

	/** A folder where a file should be is a broken reference, not a silent skip. */
	public function testSyncMetadataReportsAPathThatIsNotAFile(): void {
		$this->files = [];
		$this->folders = ['Noten' => true, 'Noten/folder.pdf' => true];

		self::assertSame(
			MobileSheetsFileLogEntry::CODE_METADATA_NOT_A_FILE,
			$this->syncMetadataFailure(new MobileSheetsFile(7, $this->sourceWebdav('Noten/folder.pdf'))),
		);
	}

	/** An existing but empty file cannot carry properties - reported, not fatal. */
	public function testSyncMetadataReportsAnUnreadableFile(): void {
		$this->files = ['Noten/empty.pdf' => ''];
		$this->folders = ['Noten' => true];

		self::assertSame(
			MobileSheetsFileLogEntry::CODE_METADATA_UNREADABLE,
			$this->syncMetadataFailure(new MobileSheetsFile(7, $this->sourceWebdav('Noten/empty.pdf'))),
		);
	}

	/**
	 * Run the metadata sync for one file, expecting a failure.
	 *
	 * @return string the stable code the failure carries
	 */
	private function syncMetadataFailure(MobileSheetsFile $file): string {
		try {
			$this->fileManager->syncMetadataIntoFile(
				$this->collectionSong(),
				$file,
				$this->user(),
				'/tmp/library.db',
			);
		} catch (SyncException $exception) {
			return $exception->errorCode();
		}

		self::fail('Expected the metadata sync to report a problem');
	}

	/**
	 * A tiny but structurally valid PDF: catalog, one page, a trailer and a
	 * startxref. Its Info dictionary holds properties of some other song, so
	 * the metadata sync always has something to write.
	 */
	private static function minimalPdf(): string {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "4 0 obj << /Title (Old title) /Creator (Unit test) >> endobj\n";

		$xrefOffset = strlen("%PDF-1.4\n" . $objects);

		return "%PDF-1.4\n"
			. $objects
			. "xref\n0 5\n0000000000 65535 f \n"
			. "trailer << /Size 5 /Root 1 0 R /Info 4 0 R >>\n"
			. "startxref\n{$xrefOffset}\n%%EOF\n";
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

	public function testRegenerateFilePathDuplicatesPhysicalFileWhenTargetBelongsToAnotherSong(): void {
		$this->files = [
			'Noten/Miles/Miles - Blue.pdf' => 'PHYSICAL_PDF_CONTENT',
		];
		$this->folders = ['Noten' => true, 'Noten/Miles' => true];
		$this->referencedPaths['/remote.php/dav/files/admin/Noten/Miles/Miles - Blue.pdf'] = 99; // song 99 owns this path

		$song = new MobileSheetsSong(
			id: 100, // different song
			title: 'Blue',
			composers: [new MobileSheetsComposer(1, 'Miles')],
			files: [new MobileSheetsFile(5, '/remote.php/dav/files/admin/Noten/source/missing.pdf')],
		);
		$file = $song->files[0];

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);

		$entry = $this->fileManager->regenerateFilePath($song, $file, $user, $this->libraryFolder, '/tmp/db.sqlite');

		self::assertSame(MobileSheetsFileLogEntry::CODE_PATH_MOVED, $entry->code);
		self::assertArrayHasKey('Noten/Miles/Miles - Blue (2).pdf', $this->files);
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

	/**
	 * Build a file Node mock that records and applies move() calls.
	 *
	 * Content reads and writes go to the in-memory tree, so the metadata sync
	 * sees the same bytes the tests put in, and every node gets a stable id,
	 * so the report's file links can be asserted.
	 */
	private function fakeFileNode(string $relative): File {
		$node = $this->createMock(File::class);

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

			if (isset($this->files[$targetRelative]) && $targetRelative !== $relative) {
				self::fail('Attempted to overwrite an existing file at: ' . $targetRelative);
			}

			$this->moves[] = ['from' => $relative, 'to' => $targetRelative];
			$this->files[$targetRelative] = $this->files[$relative];
			unset($this->files[$relative]);

			return $this->fakeFileNode($targetRelative);
		});

		$node->method('copy')->willReturnCallback(function (string $targetRelative) use ($relative): Node {
			if (isset($this->files[$targetRelative]) && $targetRelative !== $relative) {
				self::fail('Attempted to overwrite an existing file at: ' . $targetRelative);
			}
			$this->files[$targetRelative] = $this->files[$relative] ?? 'COPY_PAYLOAD';
			return $this->fakeFileNode($targetRelative);
		});

		$node->method('getContent')->willReturnCallback(
			fn (): string => $this->files[$relative] ?? '',
		);

		$node->method('putContent')->willReturnCallback(
			function (string $content) use ($relative): int {
				$this->files[$relative] = $content;

				return strlen($content);
			},
		);

		$node->method('getId')->willReturnCallback(
			function () use ($relative): int {
				return $this->nodeIds[$relative] ??= count($this->nodeIds) + 1;
			},
		);

		$node->method('getMTime')->willReturn(1700000000);

		return $node;
	}
}
