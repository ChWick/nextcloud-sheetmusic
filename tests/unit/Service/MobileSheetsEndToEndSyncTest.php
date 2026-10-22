<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Tests\Unit\Service;

use OCA\SheetMusic\Tests\TestSupport\MobileSheetsEndToEndSyncHarness;
use PHPUnit\Framework\TestCase;

/**
 * End-to-End tests for MobileSheets import/export and bidirectional synchronization.
 *
 * All tests run without mocks against real Nextcloud database tables, real Nextcloud
 * user folder files, real SQLite `mobilesheets.db` files, and real `mobilesheets_hashcodes.txt`.
 */
class MobileSheetsEndToEndSyncTest extends TestCase {
	private MobileSheetsEndToEndSyncHarness $harness;

	protected function setUp(): void {
		parent::setUp();
		$this->harness = new MobileSheetsEndToEndSyncHarness();
	}

	protected function tearDown(): void {
		$this->harness->cleanup();
		parent::tearDown();
	}

	/**
	 * Test Case 1:
	 * Create an empty Nextcloud library, sync with an empty MobileSheets library
	 * => Nextcloud library is empty, `mobilesheets.db` and `mobilesheets_hashcodes.txt` are empty.
	 */
	public function testEmptyNextcloudAndEmptyMobileSheetsSync(): void {
		$this->harness->setupEmptyLibraries();

		$this->harness->sync();

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertSame([], $ncData->songs, 'Nextcloud library songs must be empty');
		self::assertSame([], $ncData->composers, 'Nextcloud library composers must be empty');
		self::assertSame([], $ncData->books, 'Nextcloud library books must be empty');
		self::assertSame([], $ncData->collections, 'Nextcloud library collections must be empty');
		self::assertSame([], $ncData->artists, 'Nextcloud library artists must be empty');
		self::assertSame([], $ncData->genres, 'Nextcloud library genres must be empty');
		self::assertSame([], $ncData->keys, 'Nextcloud library keys must be empty');

		self::assertSame([], $this->harness->getClientTableRows('Songs'), 'mobilesheets.db Songs must be empty');
		self::assertSame([], $this->harness->getClientTableRows('Files'), 'mobilesheets.db Files must be empty');
		self::assertSame([], $this->harness->getClientTableRows('Collections'), 'mobilesheets.db Collections must be empty');
		self::assertSame([], $this->harness->getClientTableRows('Composer'), 'mobilesheets.db Composer must be empty');
		self::assertSame('', $this->harness->getClientHashCodesContent(), 'mobilesheets_hashcodes.txt must be empty');
	}

	/**
	 * Test Case 2:
	 * Empty start (same as above), create a song with a file in MobileSheets, sync with Nextcloud
	 * => the song and file exist in Nextcloud, and `mobilesheets.db` + hashes are conflict-free with no diff.
	 */
	public function testCreateSongWithFileInMobileSheetsAndSync(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		$clientSongId = $this->harness->clientCreateSong(
			title: 'Prelude in C Major',
			fileName: 'prelude_in_c.pdf',
			options: [
				'sortTitle' => 'Prelude in C Major, BWV 846',
				'stars' => 5,
				'difficulty' => 2,
				'duration' => 135,
				'keywords' => 'baroque, clavier',
				'custom' => 'Well-Tempered Clavier I',
				'notes' => 'Play legato with gentle pedal',
				'pages' => 2,
			],
		);
		self::assertSame(1, $clientSongId);

		$this->harness->sync();

		// Verify song and file exist in Nextcloud
		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs);
		$ncSong = $ncData->songs[0];
		self::assertSame('Prelude in C Major', $ncSong->title);
		self::assertSame('Prelude in C Major, BWV 846', $ncSong->sortTitle);
		self::assertSame(5, $ncSong->stars);
		self::assertSame(2, $ncSong->difficulty);
		self::assertSame(135, $ncSong->duration);
		self::assertSame('baroque, clavier', $ncSong->keywords);
		self::assertSame('Play legato with gentle pedal', $ncSong->custom);
		self::assertCount(1, $ncSong->files);
		self::assertStringEndsWith('prelude_in_c.pdf', $ncSong->files[0]->path);
		self::assertTrue($this->harness->getLibraryFolder()->nodeExists('prelude_in_c.pdf'));

		// Verify client mobilesheets.db and hashes
		$clientSongs = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongs);
		self::assertSame('Prelude in C Major', $clientSongs[0]['Title']);
		self::assertSame(5, (int)$clientSongs[0]['Stars']);

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertStringEndsWith('prelude_in_c.pdf', (string)$clientFiles[0]['Path']);
		self::assertSame(2, (int)$clientFiles[0]['SourceFilePageCount']);
		self::assertSame('1-2', $clientFiles[0]['PageOrder']);
		self::assertStringContainsString('prelude_in_c.pdf', $this->harness->getClientHashCodesContent());
	}

	/**
	 * Test Case 3a:
	 * Empty start, create a song WITH a file in Nextcloud, sync with MobileSheets
	 * => the song and file are synced to the MobileSheets client, conflict-free with no diff.
	 */
	public function testCreateSongWithFileInNextcloudAndSync(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		$ncSongId = $this->harness->ncCreateSong(
			title: 'Clair de Lune',
			fileName: 'clair_de_lune.pdf',
			options: [
				'sortTitle' => 'Clair de Lune (Suite bergamasque)',
				'stars' => 4,
				'difficulty' => 4,
				'duration' => 300,
				'keywords' => 'impressionist, piano',
				'custom' => 'Très expressif',
				'opus' => 'L. 75',
				'catalogNumber' => 'CD 82',
				'songNumber' => '3',
				'pages' => 3,
			],
		);
		self::assertGreaterThan(0, $ncSongId);

		$this->harness->sync();

		// Verify song and file exist in MobileSheets client DB and local files
		$clientSongs = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongs);
		self::assertSame('Clair de Lune', $clientSongs[0]['Title']);
		self::assertSame('Clair de Lune (Suite bergamasque)', $clientSongs[0]['SortTitle']);
		self::assertSame(4, (int)$clientSongs[0]['Stars']);
		self::assertSame(4, (int)$clientSongs[0]['Difficulty']);
		self::assertSame(300, (int)$clientSongs[0]['Duration']);
		self::assertSame('impressionist, piano', $clientSongs[0]['Keywords']);

		$clientNotes = $this->harness->getClientTableRows('SongNotes');
		self::assertCount(1, $clientNotes);
		self::assertSame('Très expressif', $clientNotes[0]['Notes']);

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertStringEndsWith('clair_de_lune.pdf', (string)$clientFiles[0]['Path']);
		self::assertSame(3, (int)$clientFiles[0]['SourceFilePageCount']);
		self::assertSame('1-3', $clientFiles[0]['PageOrder']);
		self::assertFileExists($this->harness->clientFilesDir . '/clair_de_lune.pdf');
		self::assertStringContainsString('clair_de_lune.pdf', $this->harness->getClientHashCodesContent());
	}

	/**
	 * Test Case 3b:
	 * Empty start, create a song WITHOUT a file in Nextcloud, sync with MobileSheets
	 * => the song is NOT synced with the MobileSheets client (`mobilesheets.db` and hashes remain empty),
	 *    while remaining in Nextcloud.
	 * => Then when a blank page PDF is attached to the song in Nextcloud and synced, the song IS synced
	 *    to the MobileSheets client.
	 */
	public function testCreateSongWithoutFileInNextcloudIsNotSyncedToClientUntilBlankPageAttached(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		// Create a song in Nextcloud without any file
		$ncSongId = $this->harness->ncCreateSong(
			title: 'Unfinished Lead Sheet',
			fileName: null,
			options: [
				'stars' => 3,
				'difficulty' => 1,
				'keywords' => 'draft',
			],
		);

		// Sync while song has no file
		$this->harness->sync(allowSongsWithoutFilesInNc: true);

		// Song must still exist in Nextcloud
		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs, 'Fileless song must remain in Nextcloud library');
		self::assertSame('Unfinished Lead Sheet', $ncData->songs[0]->title);
		self::assertSame([], $ncData->songs[0]->files);

		// Song must NOT be synced to MobileSheets client
		self::assertSame([], $this->harness->getClientTableRows('Songs'), 'Song without file must not be exported to mobilesheets.db Songs');
		self::assertSame([], $this->harness->getClientTableRows('Files'), 'mobilesheets.db Files must remain empty');
		self::assertSame('', $this->harness->getClientHashCodesContent(), 'mobilesheets_hashcodes.txt must remain empty');

		// Now attach a blank page PDF to the song in Nextcloud and sync again
		$this->harness->ncAttachBlankPageToSong($ncSongId, 'blank_lead_sheet.pdf');
		$this->harness->sync();

		// Now the song and blank page PDF are synced to the MobileSheets client
		$clientSongs = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongs, 'Song must be synced to mobilesheets.db once a blank page is attached');
		self::assertSame('Unfinished Lead Sheet', $clientSongs[0]['Title']);

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertStringEndsWith('blank_lead_sheet.pdf', (string)$clientFiles[0]['Path']);
		self::assertStringContainsString('blank_lead_sheet.pdf', $this->harness->getClientHashCodesContent());
	}

	/**
	 * Test Case 4a:
	 * Delete a single song on the MobileSheets client and sync with Nextcloud
	 * => song and physical file are removed from Nextcloud, `mobilesheets.db` and hashes are empty.
	 */
	public function testDeleteSingleSongOnMobileSheetsClientAndSync(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		$clientSongId = $this->harness->clientCreateSong('Nocturne Op. 9 No. 2', 'nocturne_op9_no2.pdf');
		$this->harness->sync();

		self::assertCount(1, $this->harness->getNextcloudLibraryData()->songs);
		self::assertTrue($this->harness->getLibraryFolder()->nodeExists('nocturne_op9_no2.pdf'));

		// Delete song on MobileSheets client and sync
		$this->harness->clientDeleteSong($clientSongId);
		$this->harness->sync();

		self::assertSame([], $this->harness->getNextcloudLibraryData()->songs, 'Nextcloud songs must be empty after client deletion');
		self::assertFalse($this->harness->getLibraryFolder()->nodeExists('nocturne_op9_no2.pdf'), 'Physical PDF must be deleted from Nextcloud');
		self::assertSame([], $this->harness->getClientTableRows('Songs'));
		self::assertSame([], $this->harness->getClientTableRows('Files'));
		self::assertSame('', $this->harness->getClientHashCodesContent());
	}

	/**
	 * Test Case 4b:
	 * Delete a single song in Nextcloud and sync with MobileSheets
	 * => song and physical file are removed from MobileSheets client, `mobilesheets.db` and hashes are empty.
	 */
	public function testDeleteSingleSongInNextcloudAndSync(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		$ncSongId = $this->harness->ncCreateSong('Gymnopédie No. 1', 'gymnopedie_1.pdf');
		$this->harness->sync();

		self::assertCount(1, $this->harness->getClientTableRows('Songs'));
		self::assertFileExists($this->harness->clientFilesDir . '/gymnopedie_1.pdf');

		// Delete song in Nextcloud and sync
		$this->harness->ncDeleteSong($ncSongId, deleteFiles: true);
		$this->harness->sync();

		self::assertSame([], $this->harness->getNextcloudLibraryData()->songs);
		self::assertFalse($this->harness->getLibraryFolder()->nodeExists('gymnopedie_1.pdf'));
		self::assertSame([], $this->harness->getClientTableRows('Songs'));
		self::assertSame([], $this->harness->getClientTableRows('Files'));
		self::assertFileDoesNotExist($this->harness->clientFilesDir . '/gymnopedie_1.pdf');
		self::assertSame('', $this->harness->getClientHashCodesContent());
	}

	/**
	 * Test Case 4c:
	 * Multiple songs created on both sides; delete one on MobileSheets client, delete one in Nextcloud,
	 * batch-delete remaining in Nextcloud => all sync steps remain conflict-free with zero diff.
	 */
	public function testDeleteMultipleSongsFromMobileSheetsAndNextcloud(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		// Create 2 songs on MobileSheets client and 2 songs in Nextcloud
		$this->harness->clientCreateSong('Song Alpha', 'alpha.pdf');
		$this->harness->clientCreateSong('Song Beta', 'beta.pdf');
		$this->harness->sync();

		$ncGammaId = $this->harness->ncCreateSong('Song Gamma', 'gamma.pdf');
		$ncDeltaId = $this->harness->ncCreateSong('Song Delta', 'delta.pdf');
		$this->harness->sync();

		self::assertCount(4, $this->harness->getNextcloudLibraryData()->songs);
		self::assertCount(4, $this->harness->getClientTableRows('Songs'));

		// 1. Delete 'Song Alpha' on the MobileSheets client
		$alphaClientRow = array_values(array_filter(
			$this->harness->getClientTableRows('Songs'),
			static fn (array $r): bool => $r['Title'] === 'Song Alpha'
		))[0];
		$this->harness->clientDeleteSong((int)$alphaClientRow['Id']);
		$this->harness->sync();

		$remainingTitles = array_map(static fn ($s): string => $s->title, $this->harness->getNextcloudLibraryData()->songs);
		sort($remainingTitles);
		self::assertSame(['Song Beta', 'Song Delta', 'Song Gamma'], $remainingTitles);
		self::assertFalse($this->harness->getLibraryFolder()->nodeExists('alpha.pdf'));

		// 2. Delete 'Song Gamma' in Nextcloud
		$this->harness->ncDeleteSong($ncGammaId, deleteFiles: true);
		$this->harness->sync();

		$remainingTitles = array_map(static fn ($s): string => $s->title, $this->harness->getNextcloudLibraryData()->songs);
		sort($remainingTitles);
		self::assertSame(['Song Beta', 'Song Delta'], $remainingTitles);
		self::assertCount(2, $this->harness->getClientTableRows('Songs'));
		self::assertFalse($this->harness->getLibraryFolder()->nodeExists('gamma.pdf'));

		// 3. Batch-delete 'Song Beta' and 'Song Delta' in Nextcloud
		$betaSongModel = $this->harness->songMapper->findByTitle($this->harness->libraryId, 'Song Beta');
		self::assertNotNull($betaSongModel);
		$this->harness->ncBatchDeleteSongs([(int)$betaSongModel->id, $ncDeltaId], deleteFiles: true);
		$this->harness->sync();

		self::assertSame([], $this->harness->getNextcloudLibraryData()->songs);
		self::assertSame([], $this->harness->getClientTableRows('Songs'));
		self::assertSame([], $this->harness->getClientTableRows('Files'));
		self::assertSame('', $this->harness->getClientHashCodesContent());
	}

	/**
	 * Test Case 4d:
	 * Delete individual files from a multi-file song and delete entities (collections/composers)
	 * from both MobileSheets client and Nextcloud.
	 */
	public function testDeleteSongFilesAndEntitiesFromBothSides(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		// Create song in Nextcloud with 2 files (full score + violin part)
		$ncSongId = $this->harness->ncCreateSong('Chamber Duo', 'chamber_duo_score.pdf');
		$this->harness->ncUploadFileToSong($ncSongId, 'chamber_duo_violin.pdf');
		$ncColId = $this->harness->ncCreateEntity('collection', 'Chamber Repertoire');
		$ncCompId = $this->harness->ncCreateEntity('composer', 'Mozart');
		$this->harness->ncUpdateSong($ncSongId, 'Chamber Duo', [
			'collectionIds' => [$ncColId],
			'composerIds' => [$ncCompId],
		]);
		$this->harness->sync();

		self::assertCount(2, $this->harness->getClientTableRows('Files'));
		self::assertCount(1, $this->harness->getClientTableRows('Collections'));
		self::assertCount(1, $this->harness->getClientTableRows('Composer'));

		// Delete one file ('chamber_duo_violin.pdf') on MobileSheets client and sync
		$violinFileRow = array_values(array_filter(
			$this->harness->getClientTableRows('Files'),
			static fn (array $r): bool => str_ends_with((string)$r['Path'], 'chamber_duo_violin.pdf')
		))[0];
		$this->harness->clientDeleteFile((int)$violinFileRow['Id']);
		$this->harness->sync();

		$ncSong = $this->harness->getNextcloudLibraryData()->songs[0];
		self::assertCount(1, $ncSong->files);
		self::assertStringEndsWith('chamber_duo_score.pdf', $ncSong->files[0]->path);
		self::assertFalse($this->harness->getLibraryFolder()->nodeExists('chamber_duo_violin.pdf'));

		// Delete Composer on MobileSheets client and sync
		$compClientRow = $this->harness->getClientTableRows('Composer')[0];
		$this->harness->clientDeleteEntity('Composer', (int)$compClientRow['Id']);
		$this->harness->sync();

		self::assertSame([], $this->harness->getNextcloudLibraryData()->composers);
		self::assertSame([], $this->harness->getClientTableRows('Composer'));
		self::assertSame([], $this->harness->getClientTableRows('ComposerSongs'));

		// Delete Collection in Nextcloud and sync
		$this->harness->ncDeleteEntity('collections', $ncColId);
		$this->harness->sync();

		self::assertSame([], $this->harness->getNextcloudLibraryData()->collections);
		self::assertSame([], $this->harness->getClientTableRows('Collections'));
		self::assertSame([], $this->harness->getClientTableRows('CollectionSong'));
		self::assertCount(1, $this->harness->getClientTableRows('Songs'), 'Song itself must remain intact');
	}

	/**
	 * Test Case 5a:
	 * Modifications on the MobileSheets client:
	 * - Create collections, composers, books, genres on client
	 * - Add song to collection, composer, book, genre on client and sync
	 * - Move song to a different collection and update song metadata/notes on client and sync
	 * - Remove song from collection on client and sync
	 */
	public function testModificationsOnMobileSheetsClientAndSync(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		// Initial song created in Nextcloud and synced to client
		$this->harness->ncCreateSong('Autumn Leaves', 'autumn_leaves.pdf', options: [
			'stars' => 3,
			'difficulty' => 2,
		]);
		$this->harness->sync();

		$clientSongId = (int)$this->harness->getClientTableRows('Songs')[0]['Id'];

		// 1. On MobileSheets client: create collections, composer, book, genre and link song
		$colJazzId = $this->harness->clientCreateEntity('Collections', 'Jazz Standards');
		$colGigId = $this->harness->clientCreateEntity('Collections', 'Friday Night Gig');
		$compId = $this->harness->clientCreateEntity('Composer', 'Joseph Kosma');
		$bookId = $this->harness->clientCreateEntity('Books', 'Real Book Vol. 1');
		$genreId = $this->harness->clientCreateEntity('Genres', 'Jazz');

		$this->harness->clientAddSongToEntity('Collections', $colJazzId, $clientSongId);
		$this->harness->clientAddSongToEntity('Composer', $compId, $clientSongId);
		$this->harness->clientAddSongToEntity('Books', $bookId, $clientSongId);
		$this->harness->clientAddSongToEntity('Genres', $genreId, $clientSongId);
		$this->harness->clientUpdateSong($clientSongId, [
			'Stars' => 5,
			'Difficulty' => 3,
			'Duration' => 240,
			'Keywords' => 'swing, ballad',
			'Notes' => 'Take 2 choruses on solo',
		]);

		$this->harness->sync();

		// Verify Nextcloud state reflects all client modifications
		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs);
		$ncSong = $ncData->songs[0];
		self::assertSame(5, $ncSong->stars);
		self::assertSame(3, $ncSong->difficulty);
		self::assertSame(240, $ncSong->duration);
		self::assertSame('swing, ballad', $ncSong->keywords);
		self::assertSame('Take 2 choruses on solo', $ncSong->custom);
		self::assertCount(2, $ncData->collections);
		self::assertCount(1, $ncData->composers);
		self::assertSame('Joseph Kosma', $ncData->composers[0]->name);
		self::assertCount(1, $ncData->books);
		self::assertSame('Real Book Vol. 1', $ncData->books[0]->title);
		self::assertCount(1, $ncData->genres);
		self::assertSame('Jazz', $ncData->genres[0]->name);
		self::assertSame([$ncData->composers[0]->id], array_map(static fn ($c): int => $c->id, $ncSong->composers));
		self::assertSame([$ncData->books[0]->id], array_map(static fn ($b): int => $b->id, $ncSong->books));
		self::assertSame([$ncData->genres[0]->id], array_map(static fn ($g): int => $g->id, $ncSong->genres));

		$jazzNcCol = array_values(array_filter($ncData->collections, static fn ($c): bool => $c->name === 'Jazz Standards'))[0];
		self::assertSame([$jazzNcCol->id], array_map(static fn ($c): int => $c->id, $ncSong->collections));

		// 2. On MobileSheets client: switch song from 'Jazz Standards' to 'Friday Night Gig' and rename song
		$this->harness->clientRemoveSongFromEntity('Collections', $colJazzId, $clientSongId);
		$this->harness->clientAddSongToEntity('Collections', $colGigId, $clientSongId);
		$this->harness->clientUpdateSong($clientSongId, [
			'Title' => 'Autumn Leaves (Gm)',
			'SortTitle' => 'Autumn Leaves (Gm)',
		]);

		$this->harness->sync();

		$ncData = $this->harness->getNextcloudLibraryData();
		$ncSong = $ncData->songs[0];
		self::assertSame('Autumn Leaves (Gm)', $ncSong->title);
		$gigNcCol = array_values(array_filter($ncData->collections, static fn ($c): bool => $c->name === 'Friday Night Gig'))[0];
		self::assertSame([$gigNcCol->id], array_map(static fn ($c): int => $c->id, $ncSong->collections));

		// 3. On MobileSheets client: remove song from all collections
		$this->harness->clientRemoveSongFromEntity('Collections', $colGigId, $clientSongId);
		$this->harness->sync();

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertSame([], $ncData->songs[0]->collections);
		self::assertSame([], $this->harness->getClientTableRows('CollectionSong'));
	}

	/**
	 * Test Case 5b:
	 * Modifications in Nextcloud:
	 * - Create song on MobileSheets client and sync
	 * - Create collections, composers, books, keys, instruments in Nextcloud
	 * - Add song to collection and update metadata/notes in Nextcloud (`SongService::updateSong`) and sync
	 * - Batch-update songs to another collection in Nextcloud (`SongService::batchUpdateSongs`) and sync
	 * - Remove song from collection in Nextcloud and sync
	 */
	public function testModificationsInNextcloudAndSync(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		// Start with a song created on MobileSheets client
		$this->harness->clientCreateSong('Blue Bossa', 'blue_bossa.pdf', options: [
			'stars' => 2,
			'difficulty' => 1,
		]);
		$this->harness->sync();

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs);
		$ncSongId = $ncData->songs[0]->id;

		// 1. In Nextcloud: create entities and update song metadata + entity links
		$colLatinId = $this->harness->ncCreateEntity('collection', 'Latin Setlist');
		$colEncoreId = $this->harness->ncCreateEntity('collection', 'Encore Set');
		$compDorhamId = $this->harness->ncCreateEntity('composer', 'Kenny Dorham');
		$bookRealId = $this->harness->ncCreateEntity('book', 'New Real Book');
		$keyCmId = $this->harness->ncCreateEntity('key', 'Cm');
		$instTrumpetId = $this->harness->ncCreateEntity('instrument', 'Trumpet');

		$this->harness->ncUpdateSong($ncSongId, 'Blue Bossa (Concert)', [
			'sortTitle' => 'Blue Bossa',
			'stars' => 5,
			'difficulty' => 4,
			'duration' => 210,
			'keywords' => 'bossa nova, latin',
			'custom' => 'Watch key change at bar 9',
			'collectionIds' => [$colLatinId],
			'composerIds' => [$compDorhamId],
			'bookIds' => [$bookRealId],
			'keyIds' => [$keyCmId],
			'instrumentIds' => [$instTrumpetId],
		]);

		$this->harness->sync();

		// Verify MobileSheets client DB has all updates from Nextcloud
		$clientSongs = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongs);
		self::assertSame('Blue Bossa (Concert)', $clientSongs[0]['Title']);
		self::assertSame('Blue Bossa', $clientSongs[0]['SortTitle']);
		self::assertSame(5, (int)$clientSongs[0]['Stars']);
		self::assertSame(4, (int)$clientSongs[0]['Difficulty']);
		self::assertSame(210, (int)$clientSongs[0]['Duration']);
		self::assertSame('bossa nova, latin', $clientSongs[0]['Keywords']);

		$clientNotes = $this->harness->getClientTableRows('SongNotes');
		self::assertCount(1, $clientNotes);
		self::assertSame('Watch key change at bar 9', $clientNotes[0]['Notes']);

		self::assertCount(1, $this->harness->getClientTableRows('CollectionSong'));
		self::assertCount(1, $this->harness->getClientTableRows('ComposerSongs'));
		self::assertCount(1, $this->harness->getClientTableRows('BookSongs'));
		self::assertCount(1, $this->harness->getClientTableRows('KeySongs'));
		self::assertCount(1, $this->harness->getClientTableRows('CustomGroupSongs'));

		// 2. In Nextcloud: use batchUpdateSongs to move song to both collections
		$this->harness->ncBatchUpdateSongs([$ncSongId], [
			'collections' => [$colLatinId, $colEncoreId],
		]);
		$this->harness->sync();

		self::assertCount(2, $this->harness->getClientTableRows('CollectionSong'));

		// 3. In Nextcloud: remove song from all collections
		$this->harness->ncBatchUpdateSongs([$ncSongId], [
			'collections' => [],
		]);
		$this->harness->sync();

		self::assertSame([], $this->harness->getClientTableRows('CollectionSong'));
		self::assertSame([], $this->harness->getNextcloudLibraryData()->songs[0]->collections);
	}

	/**
	 * Test Case 5c:
	 * Complex multi-step interleaved scenario where user alternates making changes
	 * on the MobileSheets client and in Nextcloud across multiple sync cycles.
	 */
	public function testInterleavedBidirectionalWorkflowRemainsConflictFreeWithZeroDiff(): void {
		$this->harness->setupEmptyLibraries();

		// Cycle 1: Empty sync
		$this->harness->sync();

		// Cycle 2: Create 2 songs + 1 collection on MobileSheets client, sync
		$s1Client = $this->harness->clientCreateSong('Sonata No. 14', 'moonlight.pdf', options: ['stars' => 5]);
		$s2Client = $this->harness->clientCreateSong('Für Elise', 'fur_elise.pdf', options: ['stars' => 4]);
		$colClient = $this->harness->clientCreateEntity('Collections', 'Beethoven Favorites');
		$compClient = $this->harness->clientCreateEntity('Composer', 'Ludwig van Beethoven');
		$this->harness->clientAddSongToEntity('Collections', $colClient, $s1Client);
		$this->harness->clientAddSongToEntity('Collections', $colClient, $s2Client);
		$this->harness->clientAddSongToEntity('Composer', $compClient, $s1Client);
		$this->harness->clientAddSongToEntity('Composer', $compClient, $s2Client);
		$this->harness->sync();

		// Cycle 3: In Nextcloud, create a 3rd song with file, a 4th song without file, add 3rd song to 'Beethoven Favorites'
		$ncData = $this->harness->getNextcloudLibraryData();
		$ncBeethovenColId = $ncData->collections[0]->id;
		$ncBeethovenCompId = $ncData->composers[0]->id;

		$ncPathetiqueId = $this->harness->ncCreateSong('Sonata No. 8 (Pathétique)', 'pathetique.pdf', options: [
			'stars' => 5,
			'collectionIds' => [$ncBeethovenColId],
			'composerIds' => [$ncBeethovenCompId],
		]);
		$ncDraftId = $this->harness->ncCreateSong('Symphony No. 5 Transcription (Draft)', null);
		$this->harness->sync(allowSongsWithoutFilesInNc: true);

		// Verify 3 songs in MobileSheets client (the 3 with files), 4 songs in Nextcloud
		self::assertCount(3, $this->harness->getClientTableRows('Songs'));
		self::assertCount(3, $this->harness->getClientTableRows('CollectionSong'));
		self::assertCount(4, $this->harness->getNextcloudLibraryData()->songs);

		// Cycle 4: On MobileSheets client, delete 'Für Elise' and update 'Sonata No. 14' notes;
		//          in Nextcloud, attach a blank page to the draft song so it syncs too
		$furEliseRow = array_values(array_filter(
			$this->harness->getClientTableRows('Songs'),
			static fn (array $r): bool => $r['Title'] === 'Für Elise'
		))[0];
		$sonata14Row = array_values(array_filter(
			$this->harness->getClientTableRows('Songs'),
			static fn (array $r): bool => $r['Title'] === 'Sonata No. 14'
		))[0];

		$this->harness->clientDeleteSong((int)$furEliseRow['Id']);
		$this->harness->clientUpdateSong((int)$sonata14Row['Id'], [
			'Notes' => 'Adagio sostenuto - Allegretto - Presto agitato',
		]);
		$this->harness->ncAttachBlankPageToSong($ncDraftId, 'symphony5_blank.pdf');
		$this->harness->sync();

		// Now 3 songs exist on both sides: Sonata No. 14, Sonata No. 8 (Pathétique), Symphony No. 5 Transcription (Draft)
		$ncSongs = $this->harness->getNextcloudLibraryData()->songs;
		self::assertCount(3, $ncSongs);
		self::assertCount(3, $this->harness->getClientTableRows('Songs'));

		// Cycle 5: In Nextcloud, delete Pathétique and add Symphony No. 5 to Beethoven Favorites
		$this->harness->ncDeleteSong($ncPathetiqueId, deleteFiles: true);
		$this->harness->ncBatchUpdateSongs([$ncDraftId], [
			'collections' => [$ncBeethovenColId],
			'composers' => [$ncBeethovenCompId],
		]);
		$this->harness->sync();

		self::assertCount(2, $this->harness->getNextcloudLibraryData()->songs);
		self::assertCount(2, $this->harness->getClientTableRows('Songs'));
		self::assertCount(2, $this->harness->getClientTableRows('CollectionSong'));
		self::assertCount(2, $this->harness->getClientTableRows('ComposerSongs'));
	}
}
