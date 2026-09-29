<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\LibrarySyncService;
use OCA\MobileSheetsManager\Service\MobileSheetsFileLogEntry;
use OCA\MobileSheetsManager\Service\MobileSheetsHashCodesFile;
use OCA\MobileSheetsManager\Service\SyncException;
use OCP\Files\Folder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers LibrarySyncService::syncLibrary(): the path phase's report and the
 * metadata phase's lines merge into one log, per-file problems become report
 * lines rather than aborting the run, and library-scope problems - the hash
 * codes file - are reported next to them.
 */
final class LibrarySyncServiceTest extends TestCase {
	/** @var list<string> temporary files to remove after each test */
	private array $temporaryFiles = [];

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		$this->temporaryFiles = [];
	}

	public function testSyncLibraryMergesThePathLogWithTheMetadataLog(): void {
		$user = $this->user();
		$pathEntry = new MobileSheetsFileLogEntry(
			1,
			'Take Five',
			$this->webdav('Noten/source/SomeFile.pdf'),
			'Moved to "Noten/Jazz/Take Five.pdf"',
			MobileSheetsFileLogEntry::LEVEL_INFO,
			MobileSheetsFileLogEntry::CODE_PATH_MOVED,
			11,
		);

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->method('regenerateFilePaths')->with(7, $user)->willReturn([$pathEntry]);
		$fileManager->method('libraryContext')->with(7, $user)->willReturn([
			'databasePath' => '/tmp/library.db',
			'targetFolder' => $this->createMock(Folder::class),
		]);
		$fileManager->method('syncMetadataIntoFile')->willReturn(MobileSheetsFileLogEntry::CODE_METADATA_UPDATED);
		$fileManager->method('nodeIdOfStoredFile')->willReturn(11);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('findSongs')->with('/tmp/library.db')->willReturn([
			$this->song(1, 'Take Five', 11),
		]);

		$log = $this->service($fileManager, $songMapper)->syncLibrary(7, $user);

		self::assertCount(2, $log);
		// The path phase keeps its line, the metadata phase appends its own.
		self::assertSame($pathEntry, $log[0]);
		self::assertSame(MobileSheetsFileLogEntry::CODE_METADATA_UPDATED, $log[1]->code);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_INFO, $log[1]->level);
		self::assertSame(1, $log[1]->songId);
		self::assertSame('Take Five', $log[1]->songTitle);
		self::assertSame(11, $log[1]->fileId, 'the report links to the file it checked');
	}
	public function testSyncLibraryReportsEveryFailureAndKeepsGoing(): void {
		$user = $this->user();

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->method('regenerateFilePaths')->willReturn([]);
		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => '/tmp/library.db',
			'targetFolder' => $this->createMock(Folder::class),
		]);
		$fileManager->method('nodeIdOfStoredFile')->willReturn(null);
		$fileManager->method('syncMetadataIntoFile')->willReturnCallback(
			static function (MobileSheetsSong $song, MobileSheetsFile $file): string {
				return match ($file->id) {
					// A problem the tool knows reports under its own code...
					11 => throw new SyncException(
						MobileSheetsFileLogEntry::CODE_METADATA_WRITE_FAILED,
						'the PDF refuses to be written',
					),
					// ...anything unexpected still lands in the report.
					22 => throw new \RuntimeException('disk on fire'),
					default => MobileSheetsFileLogEntry::CODE_METADATA_CURRENT,
				};
			},
		);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('findSongs')->willReturn([
			new MobileSheetsSong(
				1,
				'Multi',
				[
					new MobileSheetsFile(11, $this->webdav('Noten/a.pdf')),
					new MobileSheetsFile(22, $this->webdav('Noten/b.pdf')),
					new MobileSheetsFile(33, $this->webdav('Noten/c.pdf')),
				],
			),
		]);

		$log = $this->service($fileManager, $songMapper)->syncLibrary(7, $user);

		// The run continued past both failures: all three files are reported.
		self::assertCount(3, $log);
		self::assertSame(MobileSheetsFileLogEntry::CODE_METADATA_WRITE_FAILED, $log[0]->code);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_ERROR, $log[0]->level);
		self::assertSame('the PDF refuses to be written', $log[0]->message);
		self::assertSame(MobileSheetsFileLogEntry::CODE_METADATA_FAILED, $log[1]->code);
		self::assertSame('disk on fire', $log[1]->message);
		self::assertSame(MobileSheetsFileLogEntry::CODE_METADATA_CURRENT, $log[2]->code);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_INFO, $log[2]->level);
	}
	public function testSyncLibraryHandsTheHashCodesFileToTheMetadataPhase(): void {
		$user = $this->user();
		$databasePath = $this->temporaryDatabasePath();
		$hashCodesPath = MobileSheetsHashCodesFile::pathForDatabase($databasePath);
		file_put_contents($hashCodesPath, '');
		$this->temporaryFiles[] = $hashCodesPath;

		$handedOver = null;
		$fileManager = $this->createMock(FileManager::class);
		$fileManager->method('regenerateFilePaths')->willReturn([]);
		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => $databasePath,
			'targetFolder' => $this->createMock(Folder::class),
		]);
		$fileManager->method('syncMetadataIntoFile')->willReturnCallback(
			static function (
				MobileSheetsSong $song,
				MobileSheetsFile $file,
				IUser $user,
				string $path,
				?MobileSheetsHashCodesFile $hashCodes,
			) use (&$handedOver): string {
				$handedOver = $hashCodes;

				return MobileSheetsFileLogEntry::CODE_METADATA_CURRENT;
			},
		);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('findSongs')->with($databasePath)->willReturn([$this->song(1, 'Take Five', 11)]);

		$log = $this->service($fileManager, $songMapper)->syncLibrary(7, $user);

		self::assertInstanceOf(MobileSheetsHashCodesFile::class, $handedOver);
		// Only the metadata line: the hash codes file was read and saved without
		// a problem, so it earns no report line of its own.
		self::assertCount(1, $log);
		self::assertSame(MobileSheetsFileLogEntry::CODE_METADATA_CURRENT, $log[0]->code);
	}

	public function testSyncLibraryReportsAnUnreadableHashCodesFile(): void {
		$user = $this->user();
		$databasePath = $this->temporaryDatabasePath();
		$hashCodesPath = MobileSheetsHashCodesFile::pathForDatabase($databasePath);
		// Fewer lines than one entry has: parsing refuses rather than lose them.
		file_put_contents($hashCodesPath, "dangling\n");
		$this->temporaryFiles[] = $hashCodesPath;

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->method('regenerateFilePaths')->willReturn([]);
		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => $databasePath,
			'targetFolder' => $this->createMock(Folder::class),
		]);
		$fileManager->method('syncMetadataIntoFile')->willReturn(MobileSheetsFileLogEntry::CODE_METADATA_CURRENT);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('findSongs')->willReturn([$this->song(1, 'Take Five', 11)]);

		$log = $this->service($fileManager, $songMapper)->syncLibrary(7, $user);

		$libraryEntries = array_values(array_filter(
			$log,
			static fn (MobileSheetsFileLogEntry $entry): bool => $entry->songId === 0,
		));
		self::assertCount(1, $libraryEntries);
		self::assertSame(MobileSheetsFileLogEntry::CODE_HASHCODES_UNREADABLE, $libraryEntries[0]->code);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_WARNING, $libraryEntries[0]->level);
		// The metadata of the song was still checked - the problem is reported,
		// not fatal.
		self::assertSame(MobileSheetsFileLogEntry::CODE_METADATA_CURRENT, $log[1]->code);
	}

	private function service(FileManager $fileManager, SongMapper $songMapper): LibrarySyncService {
		return new LibrarySyncService(
			$fileManager,
			$songMapper,
			$this->createMock(LoggerInterface::class),
		);
	}

	/** A path below the admin user's WebDAV root, as the database stores it. */
	private function webdav(string $relative): string {
		return '/remote.php/dav/files/admin/' . $relative;
	}

	/** A song whose single file is the one the metadata phase receives. */
	private function song(int $id, string $title, int $fileId): MobileSheetsSong {
		return new MobileSheetsSong($id, $title, [new MobileSheetsFile($fileId, $this->webdav('Noten/song.pdf'))]);
	}

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		return $user;
	}

	/** A fresh path to pretend the library database lives at. */
	private function temporaryDatabasePath(): string {
		$path = tempnam(sys_get_temp_dir(), 'msm-libsync-');
		self::assertNotFalse($path);
		$this->temporaryFiles[] = $path;

		return $path;
	}
}
