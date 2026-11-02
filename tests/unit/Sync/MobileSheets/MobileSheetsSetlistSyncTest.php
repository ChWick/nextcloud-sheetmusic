<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongBookmark;
use OCA\SheetMusic\Service\SetlistService;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsSetlistSyncTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private SetlistService $setlistService;
	private string $tempDbPath;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->setlistService = new SetlistService(
			$this->libraryMapper,
			$this->entityMapper,
			$this->setlistItemMapper,
			$this->songMapper,
			$this->bookmarkMapper,
			new NullLogger(),
		);

		$this->tempDbPath = tempnam(sys_get_temp_dir(), 'setlist_sync_') . '.db';
		$this->tempFiles[] = $this->tempDbPath;
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testExportSetlistsAndSetlistSongsToMobileSheets(): void {
		$lib = $this->createTestLibrary('SetlistExportLib');

		// Create 2 songs with physical files so they are exported
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists($lib->path)) {
			$this->createdUserNodes[] = $userFolder->newFolder($lib->path);
		}
		$libFolder = $userFolder->get($lib->path);

		$file1 = $libFolder->newFile('SongA.pdf');
		$file1->putContent("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
		$this->createdUserNodes[] = $file1;

		$file2 = $libFolder->newFile('SongB.pdf');
		$file2->putContent("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
		$this->createdUserNodes[] = $file2;

		$song1 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Song 1',
			clientId: 101,
		));
		$this->attachTestFileToSong($song1, 'SongA.pdf', 201);

		$song2 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Song 2',
			clientId: 102,
		));
		$this->attachTestFileToSong($song2, 'SongB.pdf', 202);

		$bm = $this->bookmarkMapper->insert(new InternalSongBookmark(
			id: null,
			songId: (int)$song2->id,
			name: 'Adagio',
			pageNum: 3,
			position: 0,
		));

		// Create setlist with manual order: Song 2 (bookmark) first, Song 1 second
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);
		$setlist = $this->setlistService->createSetlist($user, (int)$lib->id, 'Summer Recital');
		self::assertNotNull($setlist);

		$this->setlistService->addItem($user, (int)$lib->id, (int)$setlist->id, (int)$song2->id, bookmarkId: (int)$bm->id);
		$this->setlistService->addItem($user, (int)$lib->id, (int)$setlist->id, (int)$song1->id);

		// Export
		$this->exporter->exportToFile($lib, $this->tempDbPath);

		$db = new PDO('sqlite:' . $this->tempDbPath);

		// Verify Setlists row
		$setlistRows = $db->query('SELECT * FROM Setlists')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $setlistRows);
		self::assertSame('Summer Recital', $setlistRows[0]['Name']);
		self::assertSame(0, (int)$setlistRows[0]['SortBy']);
		self::assertSame(1, (int)$setlistRows[0]['Ascending']);

		$setlistClientId = (int)$setlistRows[0]['Id'];

		// Verify SetlistSong rows: Song 2 (clientId 102) first, Song 1 (clientId 101) second
		$songRows = $db->query("SELECT SongId FROM SetlistSong WHERE SetlistId = {$setlistClientId} ORDER BY Id ASC")->fetchAll(PDO::FETCH_COLUMN);
		self::assertSame([102, 101], array_map('intval', $songRows));
	}

	public function testImportSetlistsAndSetlistSongsFromMobileSheets(): void {
		$lib = $this->createTestLibrary('SetlistImportLib');

		// Create songs in Nextcloud
		$song1 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Acoustic Intro',
			clientId: 201,
		));
		$song2 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Electric Jam',
			clientId: 202,
		));

		// Create client SQLite DB
		$db = new PDO('sqlite:' . $this->tempDbPath);
		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), LastModified INTEGER)');
		$db->exec('CREATE TABLE Setlists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), LastPage INTEGER, LastIndex INTEGER, SortBy INTEGER, Ascending INTEGER, DateCreated INTEGER, LastModified INTEGER)');
		$db->exec('CREATE TABLE SetlistSong(Id INTEGER PRIMARY KEY, SetlistId INTEGER, SongId INTEGER)');

		$tsMs = 1700000000000;
		$db->exec("INSERT INTO Songs (Id, Title, LastModified) VALUES (201, 'Acoustic Intro', {$tsMs}), (202, 'Electric Jam', {$tsMs})");
		$db->exec("INSERT INTO Setlists (Id, Name, LastPage, LastIndex, SortBy, Ascending, DateCreated, LastModified) VALUES (1, 'Live Tour 2026', 0, 0, 0, 1, {$tsMs}, {$tsMs})");
		$db->exec('INSERT INTO SetlistSong (Id, SetlistId, SongId) VALUES (1, 1, 202), (2, 1, 201)');

		// Import
		$this->importer->importFromFile($lib, $this->tempDbPath);

		// Verify Nextcloud entities
		$setlists = $this->entityMapper->findAllForLibrary((int)$lib->id, 'setlist');
		self::assertCount(1, $setlists);
		self::assertSame('Live Tour 2026', $setlists[0]->name);
		self::assertSame(0, $setlists[0]->sortBy);
		self::assertSame(1, $setlists[0]->ascending);

		// Verify ordered setlist items
		$items = $this->setlistItemMapper->findForSetlist((int)$setlists[0]->id);
		self::assertCount(2, $items);
		self::assertSame((int)$song2->id, $items[0]->songId);
		self::assertSame(0, $items[0]->position);
		self::assertSame('Electric Jam', $items[0]->songTitle);

		self::assertSame((int)$song1->id, $items[1]->songId);
		self::assertSame(1, $items[1]->position);
		self::assertSame('Acoustic Intro', $items[1]->songTitle);
	}
}
