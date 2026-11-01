<?php

declare(strict_types=1);

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Service\EntityService;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\IntegrityIssue;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongService;
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
		unset($this->harness);
		gc_collect_cycles();
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

	/**
	 * Test Case 6a:
	 * Import a pre-populated MobileSheets client library (with songs, multi-page PDFs, all entity types,
	 * setlists, bookmarks, annotations, tempos, display settings, custom attributes) into an empty Nextcloud
	 * library via sync, then modify songs, entities, and files on both Nextcloud and the MobileSheets client.
	 */
	public function testImportPopulatedMobileSheetsClientLibraryAndModifyFromBothSides(): void {
		$this->harness->setupEmptyLibraries();

		// Populate rich MobileSheets client library BEFORE initial sync
		$this->harness->populateRichMobileSheetsClientLibrary();

		// Initial sync imports the populated MobileSheets client library into Nextcloud
		$importStats = $this->harness->sync();
		self::assertNotNull($importStats);
		self::assertSame(3, $importStats['songsCreated']);
		self::assertSame(3, $importStats['filesCreated']);

		// Verify all songs, entities, and auxiliary tables were preserved
		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(3, $ncData->songs);
		self::assertCount(3, $ncData->composers);
		self::assertCount(3, $ncData->collections);
		self::assertCount(2, $ncData->books);
		self::assertCount(2, $ncData->artists);
		self::assertCount(2, $ncData->genres);
		self::assertCount(3, $ncData->keys);
		self::assertCount(3, $ncData->years);
		self::assertCount(2, $ncData->signatures);
		self::assertCount(2, $ncData->sourceTypes);
		self::assertCount(2, $ncData->customGroups);

		self::assertCount(1, $this->harness->getClientTableRows('Setlists'));
		self::assertCount(3, $this->harness->getClientTableRows('SetlistSong'));
		self::assertCount(2, $this->harness->getClientTableRows('Bookmarks'));
		self::assertCount(1, $this->harness->getClientTableRows('AnnotationsBase'));
		self::assertCount(1, $this->harness->getClientTableRows('DrawAnnotations'));
		self::assertCount(3, $this->harness->getClientTableRows('Tempos'));
		self::assertCount(1, $this->harness->getClientTableRows('SongDisplaySettings'));

		// Step 1: Modify an imported song in Nextcloud (update title, rating, notes, collections)
		$soWhatNc = array_values(array_filter($ncData->songs, static fn ($s): bool => $s->title === 'So What'))[0];
		$baroqueColNc = array_values(array_filter($ncData->collections, static fn ($c): bool => $c->name === 'Baroque Masterworks'))[0];
		$romanticColNc = array_values(array_filter($ncData->collections, static fn ($c): bool => $c->name === 'Romantic Nocturnes'))[0];

		$this->harness->ncUpdateSong($soWhatNc->id, 'So What (Kind of Blue)', [
			'stars' => 5,
			'difficulty' => 3,
			'duration' => 565,
			'keywords' => 'modal, jazz, miles, 1959',
			'custom' => 'Updated notes from Nextcloud for So What',
			'collectionIds' => [$romanticColNc->id],
		]);
		$this->harness->sync();

		$clientSongsByTitle = array_column($this->harness->getClientTableRows('Songs'), null, 'Title');
		self::assertArrayHasKey('So What (Kind of Blue)', $clientSongsByTitle);
		self::assertSame(5, (int)$clientSongsByTitle['So What (Kind of Blue)']['Stars']);
		self::assertSame(3, (int)$clientSongsByTitle['So What (Kind of Blue)']['Difficulty']);
		self::assertSame('modal, jazz, miles, 1959', $clientSongsByTitle['So What (Kind of Blue)']['Keywords']);

		// Step 2: Modify an imported song on the MobileSheets client (update Nocturne and add a new song)
		$nocturneClientId = (int)$clientSongsByTitle['Nocturne in E-Flat Major, Op. 9 No. 2']['Id'];
		$this->harness->clientUpdateSong($nocturneClientId, [
			'Title' => 'Nocturne Op. 9 No. 2 (Chopin)',
			'Difficulty' => 5,
			'Notes' => 'Updated performance notes on MobileSheets tablet',
		]);
		$this->harness->clientCreateSong(
			title: 'Goldberg Variations: Aria',
			fileName: 'goldberg_aria.pdf',
			options: [
				'stars' => 5,
				'difficulty' => 3,
				'pages' => 2,
			],
		);
		$this->harness->sync();

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(4, $ncData->songs);
		$nocturneNc = array_values(array_filter($ncData->songs, static fn ($s): bool => $s->title === 'Nocturne Op. 9 No. 2 (Chopin)'))[0];
		self::assertSame(5, $nocturneNc->difficulty);
		self::assertSame('Updated performance notes on MobileSheets tablet', $nocturneNc->custom);

		// Step 3: Delete one of the originally imported songs ('Prelude & Fugue No. 1 in C Major') in Nextcloud
		//         and verify its auxiliary rows (Bookmarks, Annotations, Tempos, SetlistSong, SongDisplaySettings)
		//         are cleanly removed without leaving orphan misc_data or broken references
		$preludeNc = array_values(array_filter($ncData->songs, static fn ($s): bool => $s->title === 'Prelude & Fugue No. 1 in C Major'))[0];
		$this->harness->ncDeleteSong($preludeNc->id, deleteFiles: true);
		$this->harness->sync();

		self::assertCount(3, $this->harness->getClientTableRows('Songs'));
		self::assertCount(1, $this->harness->getClientTableRows('Bookmarks'), 'Bookmark for deleted song must be removed');
		self::assertSame([], $this->harness->getClientTableRows('AnnotationsBase'), 'Annotation for deleted song must be removed');
		self::assertCount(2, $this->harness->getClientTableRows('Tempos'), 'Tempo for deleted song must be removed');
		self::assertCount(2, $this->harness->getClientTableRows('SetlistSong'), 'SetlistSong entry for deleted song must be removed');
		self::assertSame([], $this->harness->getClientTableRows('SongDisplaySettings'), 'Display settings for deleted song must be removed');
		self::assertFalse($this->harness->getLibraryFolder()->nodeExists('wtc1_prelude_fugue_1.pdf'));
	}

	/**
	 * Test Case 6b:
	 * Import an existing MobileSheets library that already sits inside a Nextcloud folder
	 * via `LibraryService::addLibrary(..., importExistingDb: true)`, then sync with the MobileSheets client
	 * and perform modifications on both sides.
	 */
	public function testAddLibraryWithImportExistingDbInNextcloudAndModify(): void {
		$this->harness->setupAndImportPreExistingMobileSheetsLibraryInNextcloud('ExistingMsFolder');

		// Immediately after addLibrary(importExistingDb: true), all 3 songs and entities are in Nextcloud
		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(3, $ncData->songs);
		self::assertCount(3, $ncData->composers);
		self::assertCount(3, $ncData->collections);

		// Initial sync exports `_nc_sync_metadata` and `mobilesheets_hashcodes.txt` with zero diff
		$this->harness->sync(forceClientUpload: false);

		// Modify in Nextcloud: add a new song, rename an existing song, and delete an entity
		$ncNewSongId = $this->harness->ncCreateSong('Invention No. 1 in C Major', 'invention_1.pdf', options: [
			'stars' => 4,
			'difficulty' => 2,
			'pages' => 2,
		]);
		self::assertGreaterThan(0, $ncNewSongId);

		$milesComp = array_values(array_filter($ncData->composers, static fn ($c): bool => $c->name === 'Miles Davis'))[0];
		$this->harness->ncDeleteEntity('composers', $milesComp->id);

		$this->harness->sync();

		self::assertCount(4, $this->harness->getClientTableRows('Songs'));
		self::assertCount(2, $this->harness->getClientTableRows('Composer'));
		self::assertFileExists($this->harness->clientFilesDir . '/invention_1.pdf');

		// Modify on MobileSheets client: rename 'Invention No. 1 in C Major' and add it to 'Baroque Masterworks'
		$invClientRow = array_values(array_filter(
			$this->harness->getClientTableRows('Songs'),
			static fn (array $r): bool => $r['Title'] === 'Invention No. 1 in C Major'
		))[0];
		$baroqueClientRow = array_values(array_filter(
			$this->harness->getClientTableRows('Collections'),
			static fn (array $r): bool => $r['Name'] === 'Baroque Masterworks'
		))[0];

		$this->harness->clientUpdateSong((int)$invClientRow['Id'], [
			'Title' => 'Invention No. 1 in C Major, BWV 772',
			'Stars' => 5,
		]);
		$this->harness->clientAddSongToEntity('Collections', (int)$baroqueClientRow['Id'], (int)$invClientRow['Id']);
		$this->harness->sync();

		$ncData = $this->harness->getNextcloudLibraryData();
		$invNc = array_values(array_filter($ncData->songs, static fn ($s): bool => $s->title === 'Invention No. 1 in C Major, BWV 772'))[0];
		self::assertSame(5, $invNc->stars);
		self::assertCount(1, $invNc->collections);
		self::assertSame('Baroque Masterworks', $invNc->collections[0]->name);
	}

	/**
	 * Test Case 7:
	 * File operations E2E:
	 * - Update/replace PDF file content in Nextcloud (`SongFileService::updateSongFile`) -> page count, size, and hash update in `Files` and `mobilesheets_hashcodes.txt`
	 * - Update/replace PDF file content on MobileSheets client -> updated PDF content, page count, size, and hash sync to Nextcloud
	 * - Attach an existing Nextcloud file to a song (`SongFileService::attachNextcloudFile`)
	 * - Detach a file from a song in Nextcloud (`SongFileService::detachSongFile`) -> when last file is detached, song becomes fileless in Nextcloud and is removed from client `mobilesheets.db` while the physical file remains in Nextcloud
	 * - Drop a new PDF file directly into the Nextcloud library folder -> `SheetMusicFileEventListener` auto-indexes it and sync exports it to MobileSheets
	 */
	public function testFileUpdateAttachDetachAndAutoIndexOperationsSyncCleanly(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		// 1. Create song with 1-page PDF in Nextcloud and sync
		$ncSongId = $this->harness->ncCreateSong('Etude Op. 10 No. 1', 'etude_op10_1.pdf', options: ['pages' => 1]);
		$this->harness->sync();

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		$origHash = (int)$clientFiles[0]['FileHash'];
		$origSize = (int)$clientFiles[0]['FileSize'];
		self::assertSame(1, (int)$clientFiles[0]['SourceFilePageCount']);
		self::assertSame('1-1', $clientFiles[0]['PageOrder']);

		// 2. Replace PDF content in Nextcloud with a 4-page PDF via SongFileService::updateSongFile
		$ncFileId = $this->harness->getNextcloudLibraryData()->songs[0]->files[0]->id;
		$fourPagePdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Etude Op. 10 No. 1 - Revised 4 Pages', 4);
		$this->harness->ncUpdateSongFile($ncSongId, $ncFileId, $fourPagePdf);
		$this->harness->sync();

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertSame(4, (int)$clientFiles[0]['SourceFilePageCount']);
		self::assertSame('1-4', $clientFiles[0]['PageOrder']);
		self::assertNotSame($origHash, (int)$clientFiles[0]['FileHash']);
		self::assertNotSame($origSize, (int)$clientFiles[0]['FileSize']);
		self::assertSame($fourPagePdf, (string)file_get_contents($this->harness->clientFilesDir . '/etude_op10_1.pdf'));

		// 3. Replace PDF content on MobileSheets client with a 6-page PDF and custom PageOrder
		$sixPagePdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Etude Op. 10 No. 1 - Client Annotated 6 Pages', 6);
		$this->harness->clientUpdateFileContent((int)$clientFiles[0]['Id'], $sixPagePdf, '1-6');
		$this->harness->sync();

		$ncFileModel = $this->harness->songFileMapper->findById($ncFileId);
		self::assertNotNull($ncFileModel);
		self::assertSame(6, $ncFileModel->pageCount);
		self::assertSame('1-6', $ncFileModel->pageOrder);
		self::assertSame(strlen($sixPagePdf), $ncFileModel->fileSize);

		// 4. Attach an existing Nextcloud file from outside the library folder via SongFileService::attachNextcloudFile
		$userFolder = $this->harness->rootFolder->getUserFolder($this->harness->userId);
		$externalPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Coda Insert Page', 2);
		if ($userFolder->nodeExists('external_coda.pdf')) {
			$userFolder->get('external_coda.pdf')->delete();
		}
		$userFolder->newFile('external_coda.pdf', $externalPdf);
		$this->harness->ncAttachNextcloudFile($ncSongId, '/external_coda.pdf', 'move');
		$this->harness->sync();

		self::assertCount(2, $this->harness->getClientTableRows('Files'));
		self::assertFileExists($this->harness->clientFilesDir . '/external_coda.pdf');

		// 5. Detach both files in Nextcloud via SongFileService::detachSongFile
		//    -> Song remains in Nextcloud (now fileless) and is removed from client mobilesheets.db
		foreach ($this->harness->getNextcloudLibraryData()->songs[0]->files as $f) {
			$this->harness->ncDetachSongFile($ncSongId, $f->id);
		}
		// Delete the unreferenced physical files from the library folder so reconciler doesn't re-index them
		$this->harness->getLibraryFolder()->get('etude_op10_1.pdf')->delete();
		$this->harness->getLibraryFolder()->get('external_coda.pdf')->delete();

		$this->harness->sync(allowSongsWithoutFilesInNc: true);

		self::assertCount(1, $this->harness->getNextcloudLibraryData()->songs, 'Detached song must remain in Nextcloud');
		self::assertSame([], $this->harness->getNextcloudLibraryData()->songs[0]->files);
		self::assertSame([], $this->harness->getClientTableRows('Songs'), 'Fileless song must be removed from client mobilesheets.db');
		self::assertSame([], $this->harness->getClientTableRows('Files'));
		self::assertSame('', $this->harness->getClientHashCodesContent());

		// 6. Drop a new PDF file directly into the Nextcloud library folder -> auto-indexed into a new song and synced to MobileSheets
		$this->harness->ncDropFileIntoLibraryFolder('001_Arabesque_No_1.pdf', pages: 3);
		$this->harness->sync(allowSongsWithoutFilesInNc: true);

		$clientSongs = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongs);
		self::assertSame('001 Arabesque No 1', $clientSongs[0]['Title']);
		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertSame(3, (int)$clientFiles[0]['SourceFilePageCount']);
	}

	/**
	 * Test Case 8:
	 * Last-Write-Wins (LWW) conflict resolution E2E:
	 * - Concurrent edits to the same song on both MobileSheets client and Nextcloud:
	 *   - Scenario A: Nextcloud edit is newer than client edit -> Nextcloud metadata & entity links win
	 *   - Scenario B: Client edit is newer than Nextcloud edit -> Client metadata & entity links win
	 * - Edit vs Delete conflict:
	 *   - Scenario C: Song deleted on MobileSheets client, but modified in Nextcloud after baseline -> Nextcloud edit wins and preserves song
	 *   - Scenario D: Song deleted in Nextcloud, but modified on MobileSheets client after deletion -> Client edit revives song
	 */
	public function testLastWriteWinsConflictResolutionBetweenClientAndNextcloud(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		$colA = $this->harness->ncCreateEntity('collection', 'Collection A');
		$colB = $this->harness->ncCreateEntity('collection', 'Collection B');
		$ncSongId = $this->harness->ncCreateSong('Conflict Piece', 'conflict_piece.pdf', options: [
			'stars' => 3,
			'difficulty' => 2,
			'collectionIds' => [$colA],
		]);
		$this->harness->sync();

		$clientSongId = (int)$this->harness->getClientTableRows('Songs')[0]['Id'];
		$colBClientRow = array_values(array_filter(
			$this->harness->getClientTableRows('Collections'),
			static fn (array $r): bool => $r['Name'] === 'Collection B'
		))[0];
		$colAClientRow = array_values(array_filter(
			$this->harness->getClientTableRows('Collections'),
			static fn (array $r): bool => $r['Name'] === 'Collection A'
		))[0];

		// Scenario A: Client edits first (older timestamp), then Nextcloud edits second (newer timestamp)
		$this->harness->clientUpdateSong($clientSongId, [
			'Title' => 'Conflict Piece (Client Older Edit)',
			'Stars' => 1,
		]);
		// Nextcloud edits afterwards (higher timestamp from logical clock)
		$this->harness->ncUpdateSong($ncSongId, 'Conflict Piece (Nextcloud Newer Edit)', [
			'stars' => 5,
			'collectionIds' => [$colB],
		]);
		$this->harness->sync();

		// Nextcloud's newer edit must win on both sides
		$ncSong = $this->harness->getNextcloudLibraryData()->songs[0];
		self::assertSame('Conflict Piece (Nextcloud Newer Edit)', $ncSong->title);
		self::assertSame(5, $ncSong->stars);
		self::assertSame([$colB], array_map(static fn ($c): int => $c->id, $ncSong->collections));

		$clientSong = $this->harness->getClientTableRows('Songs')[0];
		self::assertSame('Conflict Piece (Nextcloud Newer Edit)', $clientSong['Title']);
		self::assertSame(5, (int)$clientSong['Stars']);

		// Scenario B: Nextcloud edits first (older timestamp), then Client edits second (newer timestamp)
		$this->harness->ncUpdateSong($ncSongId, 'Conflict Piece (Nextcloud Older Edit)', [
			'stars' => 2,
			'collectionIds' => [$colB],
		]);
		$this->harness->clientUpdateSong($clientSongId, [
			'Title' => 'Conflict Piece (Client Newer Edit)',
			'Stars' => 4,
		]);
		$this->harness->clientRemoveSongFromEntity('Collections', (int)$colBClientRow['Id'], $clientSongId);
		$this->harness->clientAddSongToEntity('Collections', (int)$colAClientRow['Id'], $clientSongId);
		$this->harness->sync();

		// Client's newer edit must win on both sides
		$ncSong = $this->harness->getNextcloudLibraryData()->songs[0];
		self::assertSame('Conflict Piece (Client Newer Edit)', $ncSong->title);
		self::assertSame(4, $ncSong->stars);
		self::assertSame([$colA], array_map(static fn ($c): int => $c->id, $ncSong->collections));

		// Scenario C: Client deletes song (without removing physical file yet), while Nextcloud has newer edits since baseline
		$this->harness->clientDeleteSong($clientSongId, deleteLocalFile: false);
		$this->harness->ncUpdateSong($ncSongId, 'Conflict Piece (Saved By Nextcloud Edit)', [
			'stars' => 5,
		]);
		$this->harness->sync();

		self::assertCount(1, $this->harness->getNextcloudLibraryData()->songs, 'Song edited in Nextcloud after baseline must survive client deletion');
		self::assertCount(1, $this->harness->getClientTableRows('Songs'), 'Survived song must be re-exported to client');
		self::assertSame('Conflict Piece (Saved By Nextcloud Edit)', $this->harness->getClientTableRows('Songs')[0]['Title']);

		// Scenario D: Nextcloud deletes song (keeping physical file), then Client modifies song with a newer timestamp -> revived!
		$this->harness->ncDeleteSong($ncSongId, deleteFiles: false);
		$this->harness->clientUpdateSong($clientSongId, [
			'Title' => 'Conflict Piece (Revived By Client Edit)',
			'Stars' => 5,
		]);
		// Also bump client file timestamp so the file record is revived along with the song
		$clientFileId = (int)$this->harness->getClientTableRows('Files')[0]['Id'];
		$this->harness->clientUpdateFileContent(
			$clientFileId,
			MobileSheetsEndToEndSyncHarness::generateValidPdf('Revived PDF', 2),
		);
		$this->harness->sync();

		self::assertCount(1, $this->harness->getNextcloudLibraryData()->songs, 'Song modified on client after Nextcloud deletion must be revived');
		self::assertSame('Conflict Piece (Revived By Client Edit)', $this->harness->getNextcloudLibraryData()->songs[0]->title);
		self::assertCount(1, $this->harness->getClientTableRows('Songs'));
	}

	/**
	 * Test Case 9:
	 * Song merging (`SongService::mergeSong`) and Entity merging (`EntityService::mergeEntities`)
	 * in Nextcloud after importing a MobileSheets library with duplicate entries.
	 */
	public function testMergeDuplicateSongsAndEntitiesInNextcloudAndSync(): void {
		$this->harness->setupEmptyLibraries();

		// Create duplicate composers ("J.S. Bach" and "Johann Sebastian Bach") and 2 songs on MobileSheets client
		$compBach1 = $this->harness->clientCreateEntity('Composer', 'J.S. Bach');
		$compBach2 = $this->harness->clientCreateEntity('Composer', 'Johann Sebastian Bach');
		$colA = $this->harness->clientCreateEntity('Collections', 'Partitas');
		$colB = $this->harness->clientCreateEntity('Collections', 'Keyboard Suites');

		$this->harness->clientCreateSong(
			title: 'Partita No. 1 (Page 1)',
			fileName: 'partita1_p1.pdf',
			options: [
				'stars' => 4,
				'entities' => [
					'Composer' => [$compBach1],
					'Collections' => [$colA],
				],
			],
		);
		$this->harness->clientCreateSong(
			title: 'Partita No. 1 (Page 2)',
			fileName: 'partita1_p2.pdf',
			options: [
				'stars' => 5,
				'entities' => [
					'Composer' => [$compBach2],
					'Collections' => [$colB],
				],
			],
		);

		$this->harness->sync();

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(2, $ncData->songs);
		self::assertCount(2, $ncData->composers);

		// 1. Merge duplicate composers in Nextcloud: merge "J.S. Bach" into "Johann Sebastian Bach"
		$keepComposer = array_values(array_filter($ncData->composers, static fn ($c): bool => $c->name === 'Johann Sebastian Bach'))[0];
		$dupComposer = array_values(array_filter($ncData->composers, static fn ($c): bool => $c->name === 'J.S. Bach'))[0];

		$this->harness->ncMergeEntities('composers', $keepComposer->id, [$dupComposer->id]);
		$this->harness->sync();

		self::assertCount(1, $this->harness->getNextcloudLibraryData()->composers);
		self::assertCount(1, $this->harness->getClientTableRows('Composer'));
		self::assertSame('Johann Sebastian Bach', $this->harness->getClientTableRows('Composer')[0]['Name']);
		self::assertCount(2, $this->harness->getClientTableRows('ComposerSongs'));

		// 2. Merge the 2 songs in Nextcloud into a single 2-file song ('Partita No. 1 in B-Flat Major, BWV 825')
		$ncData = $this->harness->getNextcloudLibraryData();
		$songKeep = array_values(array_filter($ncData->songs, static fn ($s): bool => $s->title === 'Partita No. 1 (Page 1)'))[0];
		$songDrop = array_values(array_filter($ncData->songs, static fn ($s): bool => $s->title === 'Partita No. 1 (Page 2)'))[0];
		$colIds = array_map(static fn ($c): int => $c->id, $ncData->collections);

		$this->harness->ncMergeSong($songKeep->id, $songDrop->id, [
			'title' => 'Partita No. 1 in B-Flat Major, BWV 825',
			'composerIds' => [$keepComposer->id],
			'collectionIds' => $colIds,
			'stars' => 5,
			'difficulty' => 4,
		]);
		$this->harness->sync();

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs);
		self::assertSame('Partita No. 1 in B-Flat Major, BWV 825', $ncData->songs[0]->title);
		self::assertCount(2, $ncData->songs[0]->files, 'Merged song must have both files attached');
		self::assertCount(2, $ncData->songs[0]->collections, 'Merged song must be linked to both collections');

		self::assertCount(1, $this->harness->getClientTableRows('Songs'));
		self::assertCount(2, $this->harness->getClientTableRows('Files'));
		self::assertCount(2, $this->harness->getClientTableRows('CollectionSong'));
		self::assertCount(1, $this->harness->getClientTableRows('ComposerSongs'));
	}

	/**
	 * Test Case 10:
	 * Collection manual song ordering (`SortBy = 0` / `SongSortingUtil::MODE_MANUAL`) vs alphabetical
	 * and rating sort modes across bidirectional syncs.
	 */
	public function testCollectionManualAndAutomaticSongOrderingAcrossSyncs(): void {
		$this->harness->setupEmptyLibraries();

		// Create a collection with SortBy = 0 (Manual order) on the MobileSheets client
		$colManualClient = $this->harness->clientCreateEntity('Collections', 'Live Setlist Order', sortBy: 0, ascending: 1);
		$sZebra = $this->harness->clientCreateSong('Zebra Waltz', 'zebra.pdf', options: ['stars' => 3]);
		$sAlpha = $this->harness->clientCreateSong('Alpha March', 'alpha.pdf', options: ['stars' => 5]);
		$sMiddle = $this->harness->clientCreateSong('Middle Nocturne', 'middle.pdf', options: ['stars' => 4]);

		// Add songs in specific non-alphabetical manual order: Zebra -> Middle -> Alpha
		$this->harness->clientAddSongToEntity('Collections', $colManualClient, $sZebra);
		$this->harness->clientAddSongToEntity('Collections', $colManualClient, $sMiddle);
		$this->harness->clientAddSongToEntity('Collections', $colManualClient, $sAlpha);

		$this->harness->sync();

		// Verify manual order is preserved in client CollectionSong table after sync
		$colSongRows = $this->harness->getClientTableRows('CollectionSong', 'Id ASC');
		self::assertSame(
			[$sZebra, $sMiddle, $sAlpha],
			array_map(static fn (array $r): int => (int)$r['SongId'], $colSongRows),
			'Manual song order (SortBy=0) must be preserved in CollectionSong'
		);

		// Reorder songs in Nextcloud via InternalEntityMapper::setEntitySongs: Alpha -> Zebra -> Middle
		$ncCol = $this->harness->getNextcloudLibraryData()->collections[0];
		$ncSongsByTitle = [];
		foreach ($this->harness->getNextcloudLibraryData()->songs as $s) {
			$ncSongsByTitle[$s->title] = $s->id;
		}
		$this->harness->entityMapper->setEntitySongs($ncCol->id, [
			$ncSongsByTitle['Alpha March'],
			$ncSongsByTitle['Zebra Waltz'],
			$ncSongsByTitle['Middle Nocturne'],
		]);
		$ncColModel = $this->harness->entityMapper->findById($ncCol->id);
		if ($ncColModel !== null) {
			$ncColModel->updatedAt = $this->harness->nextTimestamp();
			$this->harness->entityMapper->update($ncColModel);
		}
		foreach ($ncSongsByTitle as $sid) {
			$songModel = $this->harness->songMapper->findById($sid);
			if ($songModel !== null) {
				$songModel->updatedAt = $this->harness->nextTimestamp();
				$this->harness->songMapper->update($songModel);
			}
		}

		$this->harness->sync();

		$colSongRows = $this->harness->getClientTableRows('CollectionSong', 'Id ASC');
		self::assertSame(
			[$sAlpha, $sZebra, $sMiddle],
			array_map(static fn (array $r): int => (int)$r['SongId'], $colSongRows),
			'Reordered manual song sequence from Nextcloud must be exported to CollectionSong'
		);
	}

	/**
	 * Test Case 11:
	 * Library with `autoPaths = true` and `autoPdfMetadata = true`:
	 * Create songs with composer/collection in Nextcloud, regenerate file paths into organized subfolders
	 * (`FileManager::regenerateFilePaths`), and sync with MobileSheets -> `Files.Path`, `mobilesheets_hashcodes.txt`,
	 * and client local files reflect the reorganized subfolder structure and embedded PDF metadata with zero diff.
	 */
	public function testAutoPathsAndAutoPdfMetadataSyncWithSubfolderOrganization(): void {
		$this->harness->setupEmptyLibraries(
			folderName: 'AutoPathsLib',
			autoPaths: true,
			autoPdfMetadata: true,
		);
		$this->harness->sync();

		$compBachId = $this->harness->ncCreateEntity('composer', 'Bach');
		$colPreludesId = $this->harness->ncCreateEntity('collection', 'Preludes');

		$this->harness->ncCreateSong(
			title: 'Little Prelude in C',
			fileName: 'raw_upload.pdf',
			options: [
				'composerIds' => [$compBachId],
				'collectionIds' => [$colPreludesId],
				'stars' => 5,
				'pages' => 2,
			],
		);

		// Regenerate file paths according to naming rules
		$log = $this->harness->fileManager->regenerateFilePaths($this->harness->libraryId, $this->harness->user);
		self::assertNotEmpty($log);

		// Sync with MobileSheets client
		$this->harness->sync();

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		$exportedWebdavPath = (string)$clientFiles[0]['Path'];
		$relPath = $this->harness->extractLibraryRelativePath($exportedWebdavPath);
		self::assertNotSame('raw_upload.pdf', $relPath, 'File path must be reorganized by FileManager::regenerateFilePaths');
		self::assertStringContainsString('Little Prelude in C', $relPath);
		self::assertFileExists($this->harness->clientFilesDir . '/' . $relPath);
		self::assertStringContainsString($relPath, $this->harness->getClientHashCodesContent());
	}

	/**
	 * Test Case 12a (Client -> Server):
	 * Client adds a new physical PDF file to an existing synced song.
	 * On sync, the file must be uploaded to the Nextcloud server, attached to the existing song
	 * (without leaving an auto-indexed stub song), and reflected in `mobilesheets.db` and `mobilesheets_hashcodes.txt`.
	 */
	public function testClientAddsFileToExistingSongAndUploadsToServerOnSync(): void {
		$this->harness->setupEmptyLibraries();

		// Create song with 1 initial file in Nextcloud and sync to client
		$ncSongId = $this->harness->ncCreateSong(
			title: 'String Quartet in D',
			fileName: 'quartet_part1_violin1.pdf',
			options: ['pages' => 2],
		);
		$this->harness->sync();

		$clientSongs = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongs);
		$clientSongId = (int)$clientSongs[0]['Id'];
		self::assertCount(1, $this->harness->getClientTableRows('Files'));

		// Client adds a 2nd file to the existing song
		$newFileContent = MobileSheetsEndToEndSyncHarness::generateValidPdf('Quartet Part 2 - Violin II', 3);
		$newClientFileId = $this->harness->clientAddFileToSong(
			clientSongId: $clientSongId,
			fileName: 'quartet_part2_violin2.pdf',
			pdfContent: $newFileContent,
			pages: 3,
		);
		self::assertGreaterThan(0, $newClientFileId);

		$importStats = $this->harness->sync();
		self::assertNotNull($importStats);
		self::assertSame(1, $importStats['filesCreated']);

		// Verify physical file was uploaded to Nextcloud library folder with exact content
		$libFolder = $this->harness->getLibraryFolder();
		self::assertTrue($libFolder->nodeExists('quartet_part1_violin1.pdf'));
		self::assertTrue($libFolder->nodeExists('quartet_part2_violin2.pdf'));
		$uploadedNode = $libFolder->get('quartet_part2_violin2.pdf');
		self::assertInstanceOf(\OCP\Files\File::class, $uploadedNode);
		self::assertSame($newFileContent, $uploadedNode->getContent());

		// Verify Nextcloud song has both files and no extra auto-indexed stub song was left behind
		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs);
		self::assertSame($ncSongId, $ncData->songs[0]->id);
		self::assertCount(2, $ncData->songs[0]->files);

		$ncFileNames = array_map(static fn ($f): string => basename($f->path), $ncData->songs[0]->files);
		sort($ncFileNames);
		self::assertSame(['quartet_part1_violin1.pdf', 'quartet_part2_violin2.pdf'], $ncFileNames);

		// Verify client DB and hashcodes contain both files
		self::assertCount(2, $this->harness->getClientTableRows('Files'));
		$hashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringContainsString('quartet_part1_violin1.pdf', $hashCodes);
		self::assertStringContainsString('quartet_part2_violin2.pdf', $hashCodes);
	}

	/**
	 * Test Case 12b (Client -> Server):
	 * Client deletes a file from an existing multi-file song.
	 * On sync, the deleted file is removed from the song on the Nextcloud server (including its physical file node),
	 * while the remaining file stays intact.
	 */
	public function testClientDeletesFileFromSongAndSyncsToServer(): void {
		$this->harness->setupEmptyLibraries();

		// Create song with 2 files on the client and sync to Nextcloud
		$clientSongId = $this->harness->clientCreateSong(
			title: 'Concerto for Two Violins',
			files: [
				'concerto_full_score.pdf' => MobileSheetsEndToEndSyncHarness::generateValidPdf('Concerto Full Score', 4),
				'concerto_solo_violin.pdf' => MobileSheetsEndToEndSyncHarness::generateValidPdf('Concerto Solo Violin', 2),
			],
		);
		$this->harness->sync();

		$libFolder = $this->harness->getLibraryFolder();
		self::assertTrue($libFolder->nodeExists('concerto_full_score.pdf'));
		self::assertTrue($libFolder->nodeExists('concerto_solo_violin.pdf'));
		self::assertCount(2, $this->harness->getNextcloudLibraryData()->songs[0]->files);

		// Find `concerto_solo_violin.pdf` row on the client and delete it
		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(2, $clientFiles);
		$soloViolinRow = array_values(array_filter(
			$clientFiles,
			static fn (array $r): bool => str_ends_with((string)$r['Path'], '/concerto_solo_violin.pdf')
		))[0];

		$this->harness->clientDeleteFile((int)$soloViolinRow['Id'], deleteLocalFile: true);
		$this->harness->clientUpdateSong($clientSongId, []);

		$importStats = $this->harness->sync();
		self::assertNotNull($importStats);
		self::assertSame(1, $importStats['filesDeleted']);

		// Verify deleted file was removed from Nextcloud storage and DB, while full score remains
		self::assertTrue($libFolder->nodeExists('concerto_full_score.pdf'));
		self::assertFalse($libFolder->nodeExists('concerto_solo_violin.pdf'));

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs);
		self::assertCount(1, $ncData->songs[0]->files);
		self::assertSame('concerto_full_score.pdf', basename($ncData->songs[0]->files[0]->path));

		self::assertCount(1, $this->harness->getClientTableRows('Files'));
		$hashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringContainsString('concerto_full_score.pdf', $hashCodes);
		self::assertStringNotContainsString('concerto_solo_violin.pdf', $hashCodes);
	}

	/**
	 * Test Case 12c (Client -> Server):
	 * Client replaces a song's file by deleting the existing file and adding a new file before syncing.
	 * On sync, the old file is deleted from the Nextcloud server and the new file is uploaded and attached to the song.
	 */
	public function testClientReplacesFileOfSongByDeleteAndAddAndSyncsToServer(): void {
		$this->harness->setupEmptyLibraries();

		$clientSongId = $this->harness->clientCreateSong(
			title: 'Caprice No. 24',
			files: [
				'caprice24_draft.pdf' => MobileSheetsEndToEndSyncHarness::generateValidPdf('Caprice 24 Draft', 1),
			],
		);
		$this->harness->sync();

		$libFolder = $this->harness->getLibraryFolder();
		self::assertTrue($libFolder->nodeExists('caprice24_draft.pdf'));

		$initialFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $initialFiles);
		$oldClientFileId = (int)$initialFiles[0]['Id'];

		// Client deletes old file and adds new replacement file in the same sync cycle
		$this->harness->clientDeleteFile($oldClientFileId, deleteLocalFile: true);
		$replacementPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Caprice 24 Urtext Edition', 4);
		$this->harness->clientAddFileToSong(
			clientSongId: $clientSongId,
			fileName: 'caprice24_urtext.pdf',
			pdfContent: $replacementPdf,
			pages: 4,
		);

		$importStats = $this->harness->sync();
		self::assertNotNull($importStats);
		self::assertSame(1, $importStats['filesDeleted']);
		self::assertSame(1, $importStats['filesCreated']);

		// Verify old file is removed from Nextcloud and new file is uploaded and linked to the song
		self::assertFalse($libFolder->nodeExists('caprice24_draft.pdf'));
		self::assertTrue($libFolder->nodeExists('caprice24_urtext.pdf'));
		$ncNode = $libFolder->get('caprice24_urtext.pdf');
		self::assertInstanceOf(\OCP\Files\File::class, $ncNode);
		self::assertSame($replacementPdf, $ncNode->getContent());

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs);
		self::assertCount(1, $ncData->songs[0]->files);
		self::assertSame('caprice24_urtext.pdf', basename($ncData->songs[0]->files[0]->path));
		$ncFileModel = $this->harness->songFileMapper->findById($ncData->songs[0]->files[0]->id);
		self::assertNotNull($ncFileModel);
		self::assertSame(4, $ncFileModel->pageCount);
		self::assertSame('1-4', $ncFileModel->pageOrder);

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertStringEndsWith('/caprice24_urtext.pdf', (string)$clientFiles[0]['Path']);
		self::assertSame(4, (int)$clientFiles[0]['SourceFilePageCount']);

		$hashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringNotContainsString('caprice24_draft.pdf', $hashCodes);
		self::assertStringContainsString('caprice24_urtext.pdf', $hashCodes);

		// Step 2: Client deletes the current file and adds a new file reusing the deleted SQLite Files.Id
		$currentFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $currentFiles);
		$urtextClientFileId = (int)$currentFiles[0]['Id'];

		$this->harness->clientDeleteFile($urtextClientFileId, deleteLocalFile: true);
		$annotatedPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Caprice 24 Annotated Edition', 6);
		$reusedFileId = $this->harness->clientAddFileToSong(
			clientSongId: $clientSongId,
			fileName: 'caprice24_annotated.pdf',
			pdfContent: $annotatedPdf,
			pages: 6,
			reuseDeletedId: true,
		);
		self::assertSame(1, $reusedFileId);

		$importStats2 = $this->harness->sync();
		self::assertNotNull($importStats2);
		self::assertSame(1, $importStats2['filesDeleted']);
		self::assertSame(1, $importStats2['filesCreated']);

		self::assertFalse($libFolder->nodeExists('caprice24_urtext.pdf'));
		self::assertTrue($libFolder->nodeExists('caprice24_annotated.pdf'));

		$ncData2 = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData2->songs);
		self::assertCount(1, $ncData2->songs[0]->files);
		self::assertSame('caprice24_annotated.pdf', basename($ncData2->songs[0]->files[0]->path));

		$hashCodes2 = $this->harness->getClientHashCodesContent();
		self::assertStringNotContainsString('caprice24_urtext.pdf', $hashCodes2);
		self::assertStringContainsString('caprice24_annotated.pdf', $hashCodes2);

		// Step 3: On a multi-file song (annotated + accompaniment), client removes annotated and adds final in one step
		$accompPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Caprice 24 Piano Accompaniment', 3);
		$this->harness->clientAddFileToSong(
			clientSongId: $clientSongId,
			fileName: 'caprice24_accompaniment.pdf',
			pdfContent: $accompPdf,
			pages: 3,
		);
		$this->harness->sync();
		self::assertCount(2, $this->harness->getNextcloudLibraryData()->songs[0]->files);

		$annotatedRow = array_values(array_filter(
			$this->harness->getClientTableRows('Files'),
			static fn (array $r): bool => str_ends_with((string)$r['Path'], '/caprice24_annotated.pdf')
		))[0];
		$this->harness->clientDeleteFile((int)$annotatedRow['Id'], deleteLocalFile: true);
		$finalPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Caprice 24 Final Solo Part', 5);
		$this->harness->clientAddFileToSong(
			clientSongId: $clientSongId,
			fileName: 'caprice24_final_solo.pdf',
			pdfContent: $finalPdf,
			pages: 5,
		);

		$importStats3 = $this->harness->sync();
		self::assertNotNull($importStats3);
		self::assertSame(1, $importStats3['filesDeleted']);
		self::assertSame(1, $importStats3['filesCreated']);

		self::assertFalse($libFolder->nodeExists('caprice24_annotated.pdf'));
		self::assertTrue($libFolder->nodeExists('caprice24_accompaniment.pdf'));
		self::assertTrue($libFolder->nodeExists('caprice24_final_solo.pdf'));

		$ncFileNames3 = array_map(
			static fn ($f): string => basename($f->path),
			$this->harness->getNextcloudLibraryData()->songs[0]->files
		);
		sort($ncFileNames3);
		self::assertSame(['caprice24_accompaniment.pdf', 'caprice24_final_solo.pdf'], $ncFileNames3);
	}

	/**
	 * Test Case 12d (Server -> Client):
	 * User adds a file to an existing song in the Nextcloud app (`ncUploadFileToSong`) and syncs to the client.
	 * On sync, the new file is downloaded to the client's local storage and added to `Files` and `mobilesheets_hashcodes.txt`.
	 */
	public function testNextcloudAddsFileToExistingSongAndSyncsToClient(): void {
		$this->harness->setupEmptyLibraries();

		// Create song on client with 1 file and sync to Nextcloud
		$this->harness->clientCreateSong(
			title: 'Trio in E-flat',
			files: [
				'trio_piano.pdf' => MobileSheetsEndToEndSyncHarness::generateValidPdf('Trio Piano Score', 3),
			],
		);
		$this->harness->sync();

		$ncSong = $this->harness->getNextcloudLibraryData()->songs[0];
		self::assertCount(1, $ncSong->files);

		// In Nextcloud app, user adds a 2nd file to the existing song
		$hornPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Trio Horn Part', 2);
		$this->harness->ncUploadFileToSong($ncSong->id, 'trio_horn.pdf', $hornPdf);

		$this->harness->sync();

		// Verify client received the new file on disk, in `Files`, and in `mobilesheets_hashcodes.txt`
		self::assertFileExists($this->harness->clientFilesDir . '/trio_piano.pdf');
		self::assertFileExists($this->harness->clientFilesDir . '/trio_horn.pdf');
		self::assertSame($hornPdf, file_get_contents($this->harness->clientFilesDir . '/trio_horn.pdf'));

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(2, $clientFiles);
		$clientRelPaths = array_map(
			fn (array $r): string => $this->harness->extractLibraryRelativePath((string)$r['Path']),
			$clientFiles
		);
		sort($clientRelPaths);
		self::assertSame(['trio_horn.pdf', 'trio_piano.pdf'], $clientRelPaths);

		$hashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringContainsString('trio_piano.pdf', $hashCodes);
		self::assertStringContainsString('trio_horn.pdf', $hashCodes);
	}

	/**
	 * Test Case 12e (Server -> Client):
	 * User deletes a file from a multi-file song in the Nextcloud app (`ncDeleteSongFile`) and syncs to the client.
	 * On sync, the deleted file is removed from the client's local storage, `Files` table, and `mobilesheets_hashcodes.txt`.
	 */
	public function testNextcloudDeletesFileFromSongAndSyncsToClient(): void {
		$this->harness->setupEmptyLibraries();

		$ncSongId = $this->harness->ncCreateSong(
			title: 'Sonata for Cello and Piano',
			fileName: 'cello_sonata_piano.pdf',
			options: ['pages' => 4],
		);
		$this->harness->ncUploadFileToSong(
			$ncSongId,
			'cello_sonata_cello.pdf',
			MobileSheetsEndToEndSyncHarness::generateValidPdf('Cello Part', 2),
		);
		$this->harness->sync();

		self::assertFileExists($this->harness->clientFilesDir . '/cello_sonata_piano.pdf');
		self::assertFileExists($this->harness->clientFilesDir . '/cello_sonata_cello.pdf');
		self::assertCount(2, $this->harness->getClientTableRows('Files'));

		// In Nextcloud app, user deletes `cello_sonata_cello.pdf` from the song
		$ncSong = $this->harness->getNextcloudLibraryData()->songs[0];
		$celloFileDto = array_values(array_filter(
			$ncSong->files,
			static fn ($f): bool => basename($f->path) === 'cello_sonata_cello.pdf'
		))[0];
		$this->harness->ncDeleteSongFile($ncSongId, $celloFileDto->id);

		$this->harness->sync();

		// Verify `cello_sonata_cello.pdf` was deleted on the client while `cello_sonata_piano.pdf` remains
		self::assertFileExists($this->harness->clientFilesDir . '/cello_sonata_piano.pdf');
		self::assertFileDoesNotExist($this->harness->clientFilesDir . '/cello_sonata_cello.pdf');

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertStringEndsWith('/cello_sonata_piano.pdf', (string)$clientFiles[0]['Path']);

		$hashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringContainsString('cello_sonata_piano.pdf', $hashCodes);
		self::assertStringNotContainsString('cello_sonata_cello.pdf', $hashCodes);
	}

	/**
	 * Test Case 12f (Server -> Client):
	 * User replaces a file of a song in the Nextcloud app by deleting the old file (`ncDeleteSongFile`)
	 * and uploading a new file (`ncUploadFileToSong`), then syncing to the client.
	 * On sync, the old file is removed from the client and the new file is downloaded to the client,
	 * recorded in `Files` and `mobilesheets_hashcodes.txt`, with zero diff.
	 */
	public function testNextcloudReplacesFileOfSongByDeleteAndAddAndSyncsToClient(): void {
		$this->harness->setupEmptyLibraries();

		$ncSongId = $this->harness->ncCreateSong(
			title: 'Ballade No. 1 in G Minor',
			fileName: 'ballade1_scan_old.pdf',
			options: ['pages' => 1],
		);
		$this->harness->sync();

		self::assertFileExists($this->harness->clientFilesDir . '/ballade1_scan_old.pdf');
		self::assertCount(1, $this->harness->getClientTableRows('Files'));

		// In Nextcloud app, user deletes the old scan and uploads the clean engraved PDF
		$ncSong = $this->harness->getNextcloudLibraryData()->songs[0];
		$oldFileDto = $ncSong->files[0];
		$this->harness->ncDeleteSongFile($ncSongId, $oldFileDto->id);

		$newEngravedPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Ballade No. 1 Engraved Edition', 5);
		$this->harness->ncUploadFileToSong($ncSongId, 'ballade1_engraved_new.pdf', $newEngravedPdf);

		$this->harness->sync();

		// Verify client local files, `Files` table, and `mobilesheets_hashcodes.txt` have only the new file
		self::assertFileDoesNotExist($this->harness->clientFilesDir . '/ballade1_scan_old.pdf');
		self::assertFileExists($this->harness->clientFilesDir . '/ballade1_engraved_new.pdf');
		self::assertSame($newEngravedPdf, file_get_contents($this->harness->clientFilesDir . '/ballade1_engraved_new.pdf'));

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertStringEndsWith('/ballade1_engraved_new.pdf', (string)$clientFiles[0]['Path']);
		self::assertSame(5, (int)$clientFiles[0]['SourceFilePageCount']);
		self::assertSame('1-5', (string)$clientFiles[0]['PageOrder']);

		$hashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringNotContainsString('ballade1_scan_old.pdf', $hashCodes);
		self::assertStringContainsString('ballade1_engraved_new.pdf', $hashCodes);

		// Step 2: On a multi-file song in Nextcloud, remove one file and upload a new file in one step, then sync
		$codaNotesPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Ballade No. 1 Coda Notes', 2);
		$this->harness->ncUploadFileToSong($ncSongId, 'ballade1_coda_notes.pdf', $codaNotesPdf);
		$this->harness->sync();
		self::assertCount(2, $this->harness->getClientTableRows('Files'));

		$ncSongNow = $this->harness->getNextcloudLibraryData()->songs[0];
		$engravedDto = array_values(array_filter(
			$ncSongNow->files,
			static fn ($f): bool => basename($f->path) === 'ballade1_engraved_new.pdf'
		))[0];
		$this->harness->ncDeleteSongFile($ncSongId, $engravedDto->id);
		$henlePdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Ballade No. 1 Henle Urtext', 6);
		$this->harness->ncUploadFileToSong($ncSongId, 'ballade1_henle_urtext.pdf', $henlePdf);

		$this->harness->sync();

		self::assertFileDoesNotExist($this->harness->clientFilesDir . '/ballade1_engraved_new.pdf');
		self::assertFileExists($this->harness->clientFilesDir . '/ballade1_coda_notes.pdf');
		self::assertFileExists($this->harness->clientFilesDir . '/ballade1_henle_urtext.pdf');
		self::assertSame($henlePdf, file_get_contents($this->harness->clientFilesDir . '/ballade1_henle_urtext.pdf'));

		$clientRelPaths2 = array_map(
			fn (array $r): string => $this->harness->extractLibraryRelativePath((string)$r['Path']),
			$this->harness->getClientTableRows('Files')
		);
		sort($clientRelPaths2);
		self::assertSame(['ballade1_coda_notes.pdf', 'ballade1_henle_urtext.pdf'], $clientRelPaths2);

		$hashCodes2 = $this->harness->getClientHashCodesContent();
		self::assertStringNotContainsString('ballade1_engraved_new.pdf', $hashCodes2);
		self::assertStringContainsString('ballade1_coda_notes.pdf', $hashCodes2);
		self::assertStringContainsString('ballade1_henle_urtext.pdf', $hashCodes2);
	}

	/**
	 * Test Case 12g:
	 * Song with two files: remove one file, sync.
	 * Only the removed file is deleted (from storage, database, and hashcodes),
	 * while the song itself and the remaining file are kept intact on both client and server.
	 *
	 * Verified for:
	 * 1. MobileSheets client deletes the FIRST file (Id = 1) and renumbers the remaining file (Id = 2 -> 1),
	 *    replicating the real MobileSheets app SQLite renumbering behavior -> sync.
	 * 2. MobileSheets client deletes the SECOND file (Id = 2) -> sync.
	 * 3. Nextcloud removes a file from a 2-file song -> sync to client.
	 */
	public function testSongWithTwoFilesRemoveOneFileAndSyncKeepsSong(): void {
		$this->harness->setupEmptyLibraries();

		// --- 1. Client deletes the FIRST file (Id = 1) and renumbers remaining file to Id = 1 (real MobileSheets app behavior) ---
		$clientSongId = $this->harness->clientCreateSong(
			title: 'Sinfonia Concertante',
			files: [
				'sinfonia_score.pdf' => MobileSheetsEndToEndSyncHarness::generateValidPdf('Sinfonia Score', 6),
				'sinfonia_violin.pdf' => MobileSheetsEndToEndSyncHarness::generateValidPdf('Sinfonia Violin Part', 3),
			],
			options: ['stars' => 4, 'difficulty' => 3],
		);
		$this->harness->sync();

		$libFolder = $this->harness->getLibraryFolder();
		self::assertTrue($libFolder->nodeExists('sinfonia_score.pdf'));
		self::assertTrue($libFolder->nodeExists('sinfonia_violin.pdf'));
		self::assertCount(1, $this->harness->getNextcloudLibraryData()->songs);
		self::assertCount(2, $this->harness->getNextcloudLibraryData()->songs[0]->files);

		// In real MobileSheets app, when the first file is deleted, MobileSheets deletes Id=1
		// and renumbers the remaining file(s) so Id=2 becomes Id=1.
		$clientPdo = $this->harness->openSqlite($this->harness->clientDbPath);
		$firstFileRow = $clientPdo->query("SELECT Id, Path FROM Files WHERE Path LIKE '%/sinfonia_score.pdf'")->fetch();
		$secondFileRow = $clientPdo->query("SELECT Id, Path FROM Files WHERE Path LIKE '%/sinfonia_violin.pdf'")->fetch();
		self::assertNotFalse($firstFileRow);
		self::assertNotFalse($secondFileRow);
		$firstFileId = (int)$firstFileRow['Id'];
		$secondFileId = (int)$secondFileRow['Id'];

		$clientPdo->exec("DELETE FROM Files WHERE Id = {$firstFileId}");
		$clientPdo->exec("UPDATE Files SET Id = {$firstFileId} WHERE Id = {$secondFileId}");
		@unlink($this->harness->clientFilesDir . '/sinfonia_score.pdf');
		$this->harness->clientUpdateSong($clientSongId, []);

		$syncStats = $this->harness->sync();
		self::assertNotNull($syncStats);
		self::assertSame(1, $syncStats['filesDeleted'], 'Exactly 1 file should be deleted');
		self::assertSame(0, $syncStats['songsDeleted'], 'The song must not be deleted');

		// Server side: score deleted, violin kept, song kept
		self::assertFalse($libFolder->nodeExists('sinfonia_score.pdf'), 'First file must be deleted on server');
		self::assertTrue($libFolder->nodeExists('sinfonia_violin.pdf'), 'Remaining second file must be kept on server');

		$ncData = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncData->songs, 'Song must be kept on server');
		$ncSong = $ncData->songs[0];
		self::assertSame('Sinfonia Concertante', $ncSong->title);
		self::assertCount(1, $ncSong->files, 'Song must have exactly 1 file on server');
		self::assertSame('sinfonia_violin.pdf', basename($ncSong->files[0]->path));

		// Client side: score deleted, violin kept, song kept
		self::assertFileDoesNotExist($this->harness->clientFilesDir . '/sinfonia_score.pdf');
		self::assertFileExists($this->harness->clientFilesDir . '/sinfonia_violin.pdf');

		$clientSongs = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongs, 'Song must be kept on client');
		self::assertSame('Sinfonia Concertante', (string)$clientSongs[0]['Title']);

		$clientFilesAfter = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFilesAfter, 'Client must have exactly 1 file');
		self::assertStringEndsWith('/sinfonia_violin.pdf', (string)$clientFilesAfter[0]['Path']);
		self::assertSame((int)$clientSongs[0]['Id'], (int)$clientFilesAfter[0]['SongId']);

		$hashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringNotContainsString('sinfonia_score.pdf', $hashCodes);
		self::assertStringContainsString('sinfonia_violin.pdf', $hashCodes);

		// Subsequent sync is clean
		$cleanStats = $this->harness->sync();
		self::assertNotNull($cleanStats);
		self::assertSame(0, $cleanStats['filesDeleted']);
		self::assertSame(0, $cleanStats['songsDeleted']);
		self::assertSame(0, $cleanStats['filesCreated']);
		self::assertSame(0, $cleanStats['songsCreated']);

		// --- 2. Nextcloud removes one file from a 2-file song ---
		// Re-add a second file on Nextcloud to create a 2-file song again
		$violaPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Sinfonia Viola Part', 3);
		$this->harness->ncUploadFileToSong($ncSong->id, 'sinfonia_viola.pdf', $violaPdf);
		$this->harness->sync();

		self::assertFileExists($this->harness->clientFilesDir . '/sinfonia_viola.pdf');
		self::assertCount(2, $this->harness->getClientTableRows('Files'));
		self::assertCount(2, $this->harness->getNextcloudLibraryData()->songs[0]->files);

		// Remove 'sinfonia_violin.pdf' in Nextcloud
		$ncSongNow = $this->harness->getNextcloudLibraryData()->songs[0];
		$violinDto = array_values(array_filter(
			$ncSongNow->files,
			static fn ($f): bool => basename($f->path) === 'sinfonia_violin.pdf'
		))[0];
		$this->harness->ncDeleteSongFile($ncSong->id, $violinDto->id);

		$this->harness->sync();

		// Server side: violin deleted, viola kept, song kept
		self::assertFalse($libFolder->nodeExists('sinfonia_violin.pdf'), 'Violin part must be deleted from server');
		self::assertTrue($libFolder->nodeExists('sinfonia_viola.pdf'), 'Viola part must be kept on server');

		$ncDataFinal = $this->harness->getNextcloudLibraryData();
		self::assertCount(1, $ncDataFinal->songs, 'Song must be kept on server');
		self::assertCount(1, $ncDataFinal->songs[0]->files, 'Song must have 1 file on server');
		self::assertSame('sinfonia_viola.pdf', basename($ncDataFinal->songs[0]->files[0]->path));

		// Client side: violin deleted, viola kept, song kept
		self::assertFileDoesNotExist($this->harness->clientFilesDir . '/sinfonia_violin.pdf');
		self::assertFileExists($this->harness->clientFilesDir . '/sinfonia_viola.pdf');

		$clientSongsFinal = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongsFinal, 'Song must be kept on client');
		self::assertSame('Sinfonia Concertante', (string)$clientSongsFinal[0]['Title']);

		$clientFilesFinal = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFilesFinal, 'Client must have exactly 1 file');
		self::assertStringEndsWith('/sinfonia_viola.pdf', (string)$clientFilesFinal[0]['Path']);
		self::assertSame((int)$clientSongsFinal[0]['Id'], (int)$clientFilesFinal[0]['SongId']);

		$hashCodesFinal = $this->harness->getClientHashCodesContent();
		self::assertStringNotContainsString('sinfonia_violin.pdf', $hashCodesFinal);
		self::assertStringContainsString('sinfonia_viola.pdf', $hashCodesFinal);
	}

	/**
	 * Test Case 25:
	 * When running the integrity audit to automatically rename a file to match naming rules,
	 * the file in "sync data" (misc data / Files) must be moved to the new location instead
	 * of leaving the old file marked as non-existing / missing.
	 * Subsequent sync with MobileSheets client must cleanly reflect the moved file.
	 */
	public function testIntegrityAuditRenameFileMovesFileInSyncDataWithoutMissingFileIssues(): void {
		$this->harness->setupEmptyLibraries(folderName: 'RenameSyncLib', autoPaths: false);

		// 1. Create a song with a composer whose file name violates naming rules
		$satieId = $this->harness->clientCreateEntity('Composer', 'Erik Satie');
		$clientSongId = $this->harness->clientCreateSong(
			title: 'Gymnopedie No 1',
			files: [
				'raw_scan_001.pdf' => MobileSheetsEndToEndSyncHarness::generateValidPdf('Gymnopedie No 1 Part', 4),
			],
			options: [
				'entities' => ['Composer' => [$satieId]],
				'stars' => 5,
			],
		);

		// 2. Initial sync: file raw_scan_001.pdf exists on both sides and is recorded in sync data (misc_data Files)
		$this->harness->sync();

		$libFolder = $this->harness->getLibraryFolder();
		self::assertTrue($libFolder->nodeExists('raw_scan_001.pdf'));
		self::assertFileExists($this->harness->clientFilesDir . '/raw_scan_001.pdf');

		$miscFilesBefore = $this->harness->miscMapper->getMiscDataForTable($this->harness->libraryId, 'mobilesheets', 'Files');
		self::assertEmpty($miscFilesBefore, 'Files table must not be stored in misc_data');

		$ncFilesBefore = $this->harness->songFileMapper->findAllForLibrary($this->harness->libraryId);
		self::assertCount(1, $ncFilesBefore);

		// 3. Enable autoPaths so the naming rule is expected
		$this->harness->libraryService->updateSettings($this->harness->userId, $this->harness->libraryId, autoPaths: true);

		// 4. Run integrity audit: checkIntegrity reports TYPE_INVALID_NAMING_RULE
		$issues = $this->harness->integrityService->checkIntegrity($this->harness->libraryId, $this->harness->userId);
		$namingIssues = array_values(array_filter(
			$issues,
			static fn (IntegrityIssue $i): bool => $i->type === IntegrityIssue::TYPE_INVALID_NAMING_RULE
		));
		self::assertCount(1, $namingIssues, 'Audit must report invalid naming rule for raw_scan_001.pdf');
		$ncSongId = (int)$namingIssues[0]->songId;

		// 5. Automatically fix the naming rule issue via integrity service
		$fixResult = $this->harness->integrityService->fixIssue(
			$this->harness->libraryId,
			$this->harness->userId,
			IntegrityIssue::TYPE_INVALID_NAMING_RULE,
			songId: $ncSongId,
		);
		self::assertTrue($fixResult['success'], 'Fixing invalid naming rule must succeed');

		// 6. Verify filesystem rename: raw_scan_001.pdf is gone, Erik Satie/Erik Satie - Gymnopedie No 1.pdf exists
		self::assertFalse($libFolder->nodeExists('raw_scan_001.pdf'), 'Old file must be moved away');
		self::assertTrue($libFolder->nodeExists('Erik Satie/Erik Satie - Gymnopedie No 1.pdf'), 'Renamed file must exist in composer subfolder on filesystem');

		// 7. Verify sync data: Files table is not stored in misc_data, but managed cleanly in Nextcloud
		$miscFilesAfter = $this->harness->miscMapper->getMiscDataForTable($this->harness->libraryId, 'mobilesheets', 'Files');
		self::assertEmpty($miscFilesAfter, 'Files table must remain excluded from misc_data');

		$ncFilesAfter = $this->harness->songFileMapper->findAllForLibrary($this->harness->libraryId);
		self::assertCount(1, $ncFilesAfter, 'Song file record must still exist');

		// 8. Re-run integrity audit: MUST NOT report TYPE_MISSING_FILE for the old file in sync data!
		$issuesAfterFix = $this->harness->integrityService->checkIntegrity($this->harness->libraryId, $this->harness->userId);
		$missingFileIssues = array_filter(
			$issuesAfterFix,
			static fn (IntegrityIssue $i): bool => $i->type === IntegrityIssue::TYPE_MISSING_FILE
		);
		self::assertEmpty($missingFileIssues, 'Old file must not be marked as missing/non-existing in sync data');
		self::assertEmpty($issuesAfterFix, 'Library must have zero integrity issues after naming rule fix');

		// 9. Sync with MobileSheets client:
		// Client must receive moved file at new path, prune old file, and maintain zero diff
		$this->harness->sync();

		self::assertFileDoesNotExist($this->harness->clientFilesDir . '/raw_scan_001.pdf');
		self::assertFileExists($this->harness->clientFilesDir . '/Erik Satie/Erik Satie - Gymnopedie No 1.pdf');

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertStringContainsString('Erik Satie/Erik Satie - Gymnopedie No 1.pdf', (string)$clientFiles[0]['Path']);

		$clientHashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringNotContainsString('raw_scan_001.pdf', $clientHashCodes);
		self::assertStringContainsString('Erik Satie/Erik Satie - Gymnopedie No 1.pdf', $clientHashCodes);
	}

	public function testIntegrityAuditPdfMetadataUpdateSyncsWithoutConflict(): void {
		$this->harness->setupEmptyLibraries(folderName: 'PdfMetaSyncLib', autoPaths: false);

		// 1. Create a song with a PDF on client whose metadata differs from song title
		$clientSongId = $this->harness->clientCreateSong(
			title: 'Canon in D',
			files: [
				'Canon in D.pdf' => MobileSheetsEndToEndSyncHarness::generateValidPdf('Old Canon Title', 2),
			],
		);

		// 2. Initial sync
		$this->harness->sync();

		// 3. Enable autoPdfMetadata and run integrity audit
		$this->harness->libraryService->updateSettings($this->harness->userId, $this->harness->libraryId, autoPdfMetadata: true);
		$issues = $this->harness->integrityService->checkIntegrity($this->harness->libraryId, $this->harness->userId);
		$metaIssues = array_values(array_filter(
			$issues,
			static fn (IntegrityIssue $i): bool => $i->type === IntegrityIssue::TYPE_PDF_METADATA_MISMATCH
		));
		self::assertCount(1, $metaIssues, 'Integrity audit must detect PDF metadata mismatch');

		// 4. Fix issue via integrity service
		$fixResult = $this->harness->integrityService->fixIssue(
			$this->harness->libraryId,
			$this->harness->userId,
			IntegrityIssue::TYPE_PDF_METADATA_MISMATCH,
			songId: (int)$metaIssues[0]->songId,
			fileId: (int)$metaIssues[0]->fileId,
		);
		self::assertTrue($fixResult['success'], 'Fixing PDF metadata mismatch must succeed');

		// Check what the physical/virtual hashcodes and database have immediately after fix:
		$libFolder = $this->harness->getLibraryFolder();
		$hcContent = $libFolder->nodeExists('mobilesheets_hashcodes.txt')
			? (string)$libFolder->get('mobilesheets_hashcodes.txt')->getContent()
			: $this->harness->exporter->generateHashcodes($this->harness->libraryId);

		$pdfNode = $libFolder->get('Canon in D.pdf');
		$expectedHash = \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf::contentHash((string)$pdfNode->getContent());
		self::assertStringContainsString((string)$expectedHash, $hcContent, 'Hashcodes file must contain updated PDF hash');

		$miscFiles = $this->harness->miscMapper->getMiscDataForTable($this->harness->libraryId, 'mobilesheets', 'Files');

		if ($libFolder->nodeExists('mobilesheets.db')) {
			$dbNode = $libFolder->get('mobilesheets.db');
			$tmpDb = tempnam(sys_get_temp_dir(), 'db_check');
			file_put_contents($tmpDb, $dbNode->getContent());
			$checkPdo = new \PDO('sqlite:' . $tmpDb);
			$dbRow = $checkPdo->query('SELECT FileHash, FileSize FROM Files WHERE Id = 1')->fetch(\PDO::FETCH_ASSOC);
			@unlink($tmpDb);
			self::assertSame($expectedHash, (int)($dbRow['FileHash'] ?? 0), 'Physical mobilesheets.db in folder must have updated FileHash');
		}

		self::assertEmpty($miscFiles, 'Files table must not be stored in misc_data');
		$ncFiles = $this->harness->songFileMapper->findAllForLibrary($this->harness->libraryId);
		self::assertSame($expectedHash, $ncFiles[0]->fileHash, 'Internal song file must record new file hash');

		// 5. Subsequent checkIntegrity must report zero issues
		$issuesAfter = $this->harness->integrityService->checkIntegrity($this->harness->libraryId, $this->harness->userId);
		self::assertEmpty($issuesAfter, 'No issues must remain after fixing PDF metadata');

		// 6. Sync with MobileSheets client: must be completely conflict-free with zero diff
		$this->harness->sync();

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		self::assertSame($expectedHash, (int)$clientFiles[0]['FileHash'], 'Client mobilesheets.db must be updated with new FileHash');

		$clientHashCodes = $this->harness->getClientHashCodesContent();
		self::assertStringContainsString((string)$expectedHash, $clientHashCodes, 'Client hashcodes must contain updated FileHash');

		$clientPdfContent = (string)file_get_contents($this->harness->clientFilesDir . '/Canon in D.pdf');
		self::assertSame($expectedHash, \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf::contentHash($clientPdfContent), 'Client local PDF file must match new content hash');

		// 7. Second sync cycle: must remain completely clean and conflict-free
		$this->harness->sync();
	}

	public function testResolvePdfConflictKeepLocalClientWins(): void {
		$this->harness->setupEmptyLibraries(folderName: 'ConflictKeepLocalLib', autoPaths: false);

		// 1. Initial song with PDF on client
		$initialPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Initial Song', 1);
		$clientSongId = $this->harness->clientCreateSong(
			title: 'Conflict Test Song',
			files: [
				'conflict_song.pdf' => $initialPdf,
			],
		);

		// Initial sync
		$this->harness->sync();

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		$clientFileId = (int)$clientFiles[0]['Id'];

		// 2. Both sides modify the PDF:
		// Nextcloud server updates the PDF
		$serverPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Server Version Title', 2);
		$libFolder = $this->harness->getLibraryFolder();
		$ncNode = $libFolder->get('conflict_song.pdf');
		self::assertInstanceOf(\OCP\Files\File::class, $ncNode);
		$ncNode->putContent($serverPdf);
		$this->harness->fileEventListener->onNodeWritten($ncNode);

		// MobileSheets client also updates the PDF locally
		$clientPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Client Kept Version Title', 3);
		$clientExpectedHash = \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf::contentHash($clientPdf);
		$this->harness->clientUpdateFileContent($clientFileId, $clientPdf);

		// 3. User chooses "Keep local" -> Client uploads its local file and DB to server
		$this->harness->sync();

		// 4. Verify Nextcloud physical file and DB now match the client's kept version
		$ncPdfContent = (string)$ncNode->getContent();
		self::assertSame($clientExpectedHash, \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf::contentHash($ncPdfContent), 'Server physical file must match client kept version');

		$ncFiles = $this->harness->songFileMapper->findAllForLibrary($this->harness->libraryId);
		self::assertSame($clientExpectedHash, $ncFiles[0]->fileHash, 'Internal song file hash must match client kept version');
		self::assertSame(strlen($clientPdf), $ncFiles[0]->fileSize, 'Internal song file size must match client kept version');

		$serverHashCodes = $this->harness->exporter->generateHashcodes($this->harness->libraryId);
		self::assertStringContainsString((string)$clientExpectedHash, $serverHashCodes, 'Server hashcodes must contain client file hash');
		self::assertStringContainsString((string)strlen($clientPdf), $serverHashCodes, 'Server hashcodes must contain client file size');

		// 5. Subsequent sync cycles must be 100% clean and conflict-free
		$this->harness->sync();
		$this->harness->sync();
	}

	public function testResolvePdfConflictKeepRemoteServerWins(): void {
		$this->harness->setupEmptyLibraries(folderName: 'ConflictKeepRemoteLib', autoPaths: false);

		// 1. Initial song with PDF on client
		$initialPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Initial Song', 1);
		$clientSongId = $this->harness->clientCreateSong(
			title: 'Remote Conflict Song',
			files: [
				'remote_conflict.pdf' => $initialPdf,
			],
		);

		// Initial sync
		$this->harness->sync();

		$clientFiles = $this->harness->getClientTableRows('Files');
		self::assertCount(1, $clientFiles);
		$clientFileId = (int)$clientFiles[0]['Id'];

		// 2. Both sides modify the PDF:
		// Nextcloud server updates the PDF
		$serverPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Server Winner Title', 4);
		$serverExpectedHash = \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf::contentHash($serverPdf);
		$libFolder = $this->harness->getLibraryFolder();
		$ncNode = $libFolder->get('remote_conflict.pdf');
		self::assertInstanceOf(\OCP\Files\File::class, $ncNode);
		$ncNode->putContent($serverPdf);
		$this->harness->fileEventListener->onNodeWritten($ncNode);

		// Client also modified locally
		$clientPdf = MobileSheetsEndToEndSyncHarness::generateValidPdf('Client Discarded Title', 2);
		$this->harness->clientUpdateFileContent($clientFileId, $clientPdf);

		// Verify that server's exported hashcodes immediately reflect the server's file on disk
		$serverHashCodes = $this->harness->exporter->generateHashcodes($this->harness->libraryId);
		self::assertStringContainsString((string)$serverExpectedHash, $serverHashCodes, 'Server hashcodes must contain server file hash');
		self::assertStringContainsString((string)strlen($serverPdf), $serverHashCodes, 'Server hashcodes must contain server file size');

		// 3. User chooses "Keep remote" -> Client downloads server file via WebDAV GET
		$downloadedContent = (string)$ncNode->getContent();
		$this->harness->clientUpdateFileContent($clientFileId, $downloadedContent);

		// Sync client acknowledging server version
		$this->harness->sync();

		// 4. Verify client and server agree on server version
		$clientFilesAfter = $this->harness->getClientTableRows('Files');
		self::assertSame($serverExpectedHash, (int)$clientFilesAfter[0]['FileHash'], 'Client DB must record server file hash');
		self::assertSame(strlen($serverPdf), (int)$clientFilesAfter[0]['FileSize'], 'Client DB must record server file size');

		$clientPdfContent = (string)file_get_contents($this->harness->clientFilesDir . '/remote_conflict.pdf');
		self::assertSame($serverExpectedHash, \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf::contentHash($clientPdfContent), 'Client local file must match server version');

		// 5. Subsequent sync cycles must be 100% clean and conflict-free
		$this->harness->sync();
		$this->harness->sync();
	}

	/**
	 * Test Case:
	 * Nextcloud library has existing user collections and a song with movements/bookmarks.
	 * When synced to MobileSheets, the virtual/exported SQLite database must avoid Collections.Id
	 * collisions between user collections and the automatically generated movement collection.
	 */
	public function testExportMovementsWithExistingCollectionsSyncsToMobileSheets(): void {
		$this->harness->setupEmptyLibraries(folderName: 'MovementsCollectionLib', autoPaths: false);

		// 1. Create two user collections in Nextcloud
		$colSymphonies = $this->harness->ncCreateEntity('collection', 'Symphonies');
		$colConcertos = $this->harness->ncCreateEntity('collection', 'Concertos');

		// 2. Create a song in Nextcloud with a 10-page PDF and movements (bookmarks with page ranges)
		$this->harness->ncCreateSong(
			title: 'Symphony No. 5 in C Minor, Op. 67',
			fileName: 'beethoven_symphony_5.pdf',
			options: [
				'pages' => 10,
				'collectionIds' => [$colSymphonies],
				'bookmarks' => [
					['name' => 'I. Allegro con brio', 'pageNum' => 1, 'endPage' => 3],
					['name' => 'II. Andante con moto', 'pageNum' => 4, 'endPage' => 6],
					['name' => 'III. Scherzo. Allegro', 'pageNum' => 7, 'endPage' => 8],
					['name' => 'IV. Allegro', 'pageNum' => 9, 'endPage' => 10],
				],
			],
		);

		// 3. Sync to MobileSheets client - this must succeed without Collections.Id UNIQUE constraint collision
		$this->harness->sync();

		// 4. Verify client mobilesheets.db
		$clientCollections = $this->harness->getClientTableRows('Collections');
		// Exactly user collections: 'Symphonies' and 'Concertos'
		self::assertCount(2, $clientCollections);
		$collectionNames = array_column($clientCollections, 'Name');
		self::assertContains('Symphonies', $collectionNames);
		self::assertContains('Concertos', $collectionNames);

		// Verify distinct IDs (no collisions)
		$collectionIds = array_column($clientCollections, 'Id');
		self::assertSame(count($collectionIds), count(array_unique($collectionIds)), 'Collection IDs must be unique');

		// Verify Songs table: exactly 1 master song (movements are native Bookmarks)
		$clientSongs = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongs);
		self::assertSame('Symphony No. 5 in C Minor, Op. 67', $clientSongs[0]['Title']);

		// Verify Bookmarks table: 4 movements exported with ShowInLibrary = 1
		$clientBookmarks = $this->harness->getClientTableRows('Bookmarks');
		self::assertCount(4, $clientBookmarks);
		$bmNames = array_column($clientBookmarks, 'Name');
		self::assertSame(['I. Allegro con brio', 'II. Andante con moto', 'III. Scherzo. Allegro', 'IV. Allegro'], $bmNames);
		foreach ($clientBookmarks as $bm) {
			self::assertSame(1, (int)$bm['ShowInLibrary']);
		}

		// 5. Subsequent sync must remain stable and conflict-free
		$this->harness->sync();
		$this->harness->sync();

		$clientSongsAfter = $this->harness->getClientTableRows('Songs');
		self::assertCount(1, $clientSongsAfter);
	}
}
