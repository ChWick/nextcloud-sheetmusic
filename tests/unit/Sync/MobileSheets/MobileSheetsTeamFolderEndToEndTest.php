<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\FolderStorageManager;
use OCA\SheetMusic\Service\IntegrityIssue;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Tests\TestSupport\MobileSheetsEndToEndSyncHarness;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end import and sync of a library that lives in a team folder (groupfolders app).
 *
 * The filecache stores team folder paths relative to the team folder storage
 * ("__groupfolders/3/Noten/..." for the legacy layout, "files/Noten/..." for a
 * separate storage), not relative to the user's folder. Song file paths must still
 * come out as the WebDAV path the user sees, below the team folder's mount point.
 *
 * Skipped when the groupfolders app is not enabled.
 */
class MobileSheetsTeamFolderEndToEndTest extends TestCase {
	private const GROUP_ID = 'sheetmusic_team_e2e';
	private const LIBRARY_SUBFOLDER = 'Noten';

	private ?MobileSheetsEndToEndSyncHarness $harness = null;
	private ?int $teamFolderId = null;
	private string $mountPoint = '';

	protected function setUp(): void {
		parent::setUp();

		$appManager = Server::get(IAppManager::class);
		if (!$appManager->isEnabledForAnyone('groupfolders')) {
			self::markTestSkipped('The groupfolders (Team folders) app is not enabled');
		}
		$appManager->loadApp('groupfolders');
	}

	protected function tearDown(): void {
		$this->harness?->cleanup();
		$this->harness = null;

		if ($this->teamFolderId !== null) {
			$folderManager = Server::get(FolderManager::class);
			$folder = $folderManager->getFolder($this->teamFolderId);
			if ($folder !== null) {
				try {
					Server::get(FolderStorageManager::class)->deleteStoragesForFolder($folder);
				} catch (\Throwable) {
					// Best effort
				}
			}
			$folderManager->removeFolder($this->teamFolderId);
			$this->teamFolderId = null;
		}

		$group = Server::get(IGroupManager::class)->get(self::GROUP_ID);
		$group?->delete();

		\OC_Util::tearDownFS();
		gc_collect_cycles();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function storageLayouts(): array {
		return [
			'legacy root storage (__groupfolders/<id>)' => [false],
			'separate storage' => [true],
		];
	}

	#[DataProvider('storageLayouts')]
	public function testImportedLibraryInTeamFolderHasNoMissingOrOrphanFiles(bool $separateStorage): void {
		$this->harness = new MobileSheetsEndToEndSyncHarness();
		$this->createTeamFolderFor($this->harness->userId, $separateStorage);

		$libraryFolder = $this->mountPoint . '/' . self::LIBRARY_SUBFOLDER;
		$this->harness->setupAndImportPreExistingMobileSheetsLibraryInNextcloud($libraryFolder);

		// The library really sits on the team folder mount, not in the home storage
		$libraryNode = $this->harness->getLibraryFolder();
		self::assertSame(
			'/' . $this->harness->userId . '/files/' . $this->mountPoint . '/',
			$libraryNode->getMountPoint()->getMountPoint(),
		);

		$this->assertPathsBelowTeamFolder();
		$this->assertNoMissingOrOrphanFiles();

		// The exact case from the bug report: a nested path with spaces, parentheses and an apostrophe
		$fileName = "Tower Of Power/Tower Of Power - Urban Renewal/Tower Of Power - Urban Renewal - (To Say The Least) You're The Most.pdf";
		$this->harness->clientCreateSong(title: "(To Say The Least) You're The Most", fileName: $fileName);

		// sync() asserts integrity and a zero diff between Nextcloud and the client
		$this->harness->sync();

		$this->assertPathsBelowTeamFolder();
		$this->assertNoMissingOrOrphanFiles();

		$expected = MobileSheetsPathResolver::WEBDAV_ROOT . '/' . $this->harness->userId . '/' . $libraryFolder . '/' . $fileName;
		$allPaths = [];
		foreach ($this->harness->getNextcloudLibraryData()->songs as $song) {
			foreach ($song->files as $file) {
				$allPaths[] = $file->path;
			}
		}
		self::assertContains($expected, $allPaths);

		$clientPaths = array_map(
			static fn (array $row): string => (string)$row['Path'],
			$this->harness->getClientTableRows('Files'),
		);
		self::assertContains($expected, $clientPaths, 'The exported mobilesheets.db must reference the team folder path');
	}

	private function createTeamFolderFor(string $userId, bool $separateStorage): void {
		$groupManager = Server::get(IGroupManager::class);
		$group = $groupManager->get(self::GROUP_ID) ?? $groupManager->createGroup(self::GROUP_ID);
		self::assertNotNull($group);
		$user = $this->harness?->user;
		self::assertNotNull($user);
		$group->addUser($user);

		$this->mountPoint = 'SheetMusic Team E2E ' . bin2hex(random_bytes(3));
		$folderManager = Server::get(FolderManager::class);
		$this->teamFolderId = $folderManager->createFolder($this->mountPoint);
		$folderManager->addApplicableGroup($this->teamFolderId, self::GROUP_ID);

		if (!$separateStorage) {
			$this->convertToLegacyRootStorage($this->teamFolderId);
		}

		// Mounts are set up once per request; pick up the new team folder
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($userId);

		$userFolder = $this->harness->rootFolder->getUserFolder($userId);
		self::assertTrue($userFolder->nodeExists($this->mountPoint), 'Team folder must be mounted for the user');
	}

	/**
	 * Team folders created by older groupfolders versions live below
	 * "__groupfolders/<id>" in the root storage; current versions always create
	 * a separate storage. Switch the folder over to the legacy layout.
	 */
	private function convertToLegacyRootStorage(int $folderId): void {
		['storage_id' => $storageId, 'root_id' => $rootId] = Server::get(FolderStorageManager::class)
			->initRootAndStorageForFolder($folderId, false, []);

		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('group_folders')
			->set('options', $qb->createNamedParameter(json_encode(['separate-storage' => false])))
			->set('storage_id', $qb->createNamedParameter($storageId))
			->set('root_id', $qb->createNamedParameter($rootId))
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
		$qb->executeStatement();
	}

	private function assertPathsBelowTeamFolder(): void {
		$prefix = MobileSheetsPathResolver::WEBDAV_ROOT . '/' . $this->harness?->userId . '/' . $this->mountPoint . '/' . self::LIBRARY_SUBFOLDER . '/';
		$songs = $this->harness?->getNextcloudLibraryData()->songs ?? [];
		self::assertNotEmpty($songs);
		foreach ($songs as $song) {
			foreach ($song->files as $file) {
				self::assertStringStartsWith($prefix, $file->path, sprintf('File of song "%s"', $song->title));
				self::assertStringNotContainsString('__groupfolders', $file->path);
			}
		}
	}

	private function assertNoMissingOrOrphanFiles(): void {
		$harness = $this->harness;
		self::assertNotNull($harness);
		$issues = $harness->integrityService->checkIntegrity($harness->libraryId, $harness->userId, false);
		$fileIssues = array_values(array_filter(
			$issues,
			static fn (IntegrityIssue $i): bool => in_array($i->type, [IntegrityIssue::TYPE_MISSING_FILE, IntegrityIssue::TYPE_ORPHAN_FILE], true),
		));
		self::assertSame([], array_map(static fn (IntegrityIssue $i): string => $i->type . ': ' . $i->message, $fileIssues));
	}
}
