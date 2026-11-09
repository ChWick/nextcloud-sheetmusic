<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\SublibraryMapper;
use OCA\SheetMusic\Service\SublibraryService;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class SublibraryServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private SublibraryService $service;
	private int $testLibId;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$sublibraryMapper = new SublibraryMapper($this->dbConn);
		$this->service = new SublibraryService($sublibraryMapper, $this->entityMapper, $this->dbConn);

		$lib = $this->createTestLibrary('/SublibServiceTest/scores.db');
		$this->testLibId = (int)$lib->id;
	}

	protected function tearDown(): void {
		$this->cleanupLibraryData($this->testLibId);
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testGetMatchingSongIdsWithFilters(): void {
		// Song 1: Organ piece by Bach
		$song1 = $this->insertTestSong($this->testLibId, 'Toccata in D minor');
		$instOrgan = $this->insertTestEntity($this->testLibId, 'instrument', 'Orgel');
		$compBach = $this->insertTestEntity($this->testLibId, 'composer', 'J.S. Bach');
		$this->entityMapper->linkSongEntity((int)$song1->id, (int)$instOrgan->id);
		$this->entityMapper->linkSongEntity((int)$song1->id, (int)$compBach->id);

		// Song 2: Piano piece by Bach
		$song2 = $this->insertTestSong($this->testLibId, 'Well-Tempered Clavier');
		$instPiano = $this->insertTestEntity($this->testLibId, 'instrument', 'Klavier');
		$this->entityMapper->linkSongEntity((int)$song2->id, (int)$instPiano->id);
		$this->entityMapper->linkSongEntity((int)$song2->id, (int)$compBach->id);

		// Song 3: Bass piece by Pastorius
		$song3 = $this->insertTestSong($this->testLibId, 'Portrait of Tracy');
		$instBass = $this->insertTestEntity($this->testLibId, 'instrument', 'Bass');
		$this->entityMapper->linkSongEntity((int)$song3->id, (int)$instBass->id);

		// Sublibrary 1: Instrument = Orgel
		$subOrgan = $this->service->createSublibrary($this->testLibId, 'Organ Only', 'orgel', [
			'instrument' => ['Orgel'],
		]);
		$organMatches = $this->service->getMatchingSongIds($subOrgan);
		self::assertTrue(isset($organMatches[(int)$song1->id]));
		self::assertFalse(isset($organMatches[(int)$song2->id]));
		self::assertFalse(isset($organMatches[(int)$song3->id]));

		// Sublibrary 2: Instrument = Orgel OR Klavier
		$subKeys = $this->service->createSublibrary($this->testLibId, 'Keyboards', 'keys', [
			'instrument' => ['Orgel', 'Klavier'],
		]);
		$keysMatches = $this->service->getMatchingSongIds($subKeys);
		self::assertTrue(isset($keysMatches[(int)$song1->id]));
		self::assertTrue(isset($keysMatches[(int)$song2->id]));
		self::assertFalse(isset($keysMatches[(int)$song3->id]));

		// Sublibrary 3: Instrument = Klavier AND Composer = J.S. Bach
		$subBachPiano = $this->service->createSublibrary($this->testLibId, 'Bach Piano', 'bach-piano', [
			'instrument' => ['Klavier'],
			'composer' => ['J.S. Bach'],
		]);
		$bachPianoMatches = $this->service->getMatchingSongIds($subBachPiano);
		self::assertFalse(isset($bachPianoMatches[(int)$song1->id]));
		self::assertTrue(isset($bachPianoMatches[(int)$song2->id]));
		self::assertFalse(isset($bachPianoMatches[(int)$song3->id]));
	}

	public function testEnsureSongMatchesSublibraryPreventDroppingInvariant(): void {
		$sub = $this->service->createSublibrary($this->testLibId, 'Organ Library', 'orgel', [
			'instrument' => ['Orgel', 'Klavier'],
		]);

		// Case A: Song already has Orgel -> should NOT modify or add Klavier
		$songA = $this->insertTestSong($this->testLibId, 'Piece A');
		$instOrgel = $this->insertTestEntity($this->testLibId, 'instrument', 'Orgel');
		$this->entityMapper->linkSongEntity((int)$songA->id, (int)$instOrgel->id);

		$modifiedA = $this->service->ensureSongMatchesSublibrary($sub, (int)$songA->id);
		self::assertFalse($modifiedA);
		$entitiesA = $this->entityMapper->findEntitiesForSong((int)$songA->id, 'instrument');
		self::assertCount(1, $entitiesA);
		self::assertSame('Orgel', $entitiesA[0]->name);

		// Case B: Song has no instrument -> should inject primary instrument ('Orgel')
		$songB = $this->insertTestSong($this->testLibId, 'Piece B');
		$modifiedB = $this->service->ensureSongMatchesSublibrary($sub, (int)$songB->id);
		self::assertTrue($modifiedB);
		$entitiesB = $this->entityMapper->findEntitiesForSong((int)$songB->id, 'instrument');
		self::assertCount(1, $entitiesB);
		self::assertSame('Orgel', $entitiesB[0]->name);
		self::assertTrue($this->service->matchesSong($sub, (int)$songB->id));
	}

	public function testRemoveSublibraryAttributes(): void {
		$sub = $this->service->createSublibrary($this->testLibId, 'Organ Library', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		$song = $this->insertTestSong($this->testLibId, 'Piece with Organ');
		$instOrgel = $this->insertTestEntity($this->testLibId, 'instrument', 'Orgel');
		$compBach = $this->insertTestEntity($this->testLibId, 'composer', 'J.S. Bach');
		$this->entityMapper->linkSongEntity((int)$song->id, (int)$instOrgel->id);
		$this->entityMapper->linkSongEntity((int)$song->id, (int)$compBach->id);

		self::assertTrue($this->service->matchesSong($sub, (int)$song->id));

		$this->service->removeSublibraryAttributes($sub, (int)$song->id);

		self::assertFalse($this->service->matchesSong($sub, (int)$song->id));
		// Composer should still be linked
		$composers = $this->entityMapper->findEntitiesForSong((int)$song->id, 'composer');
		self::assertCount(1, $composers);
		self::assertSame('J.S. Bach', $composers[0]->name);
	}
}
