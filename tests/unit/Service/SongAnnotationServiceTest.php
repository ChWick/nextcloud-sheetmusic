<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalAnnotationSyncMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Service\SongAnnotationService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBaselineSchema;
use PDO;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class SongAnnotationServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private SongAnnotationService $service;
	private InternalSong $song;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('AnnotationService Test Lib');
		$syncMapper = new InternalAnnotationSyncMapper($this->dbConn);
		$this->service = new SongAnnotationService(
			$this->annotationMapper,
			$syncMapper,
			songFileMapper: $this->songFileMapper,
		);
		$this->song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'ann-service-test-song-uuid',
			libraryId: (int)$library->id,
			title: 'Clair de Lune',
		));
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testServiceLifecycle(): void {
		$songId = (int)$this->song->id;

		// 1. Initially empty
		self::assertEmpty($this->service->getAnnotationsForSong($songId));

		// 2. Save annotation via service
		$ann = new InternalSongAnnotation(
			id: null,
			uuid: 'svc-ann-1',
			songId: $songId,
			fileId: 45,
			pageNumber: 0,
			layerIndex: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.1,
			width: 0.5,
			height: 0.5,
			style: ['stroke' => '#0000ff'],
			data: ['path' => 'M 100 100 L 500 500'],
		);

		$saved = $this->service->saveAnnotation($ann);
		self::assertNotNull($saved->id);

		$list = $this->service->getAnnotationsForSong($songId);
		self::assertCount(1, $list);

		// 3. Delete annotation via service
		$this->service->deleteAnnotation((int)$saved->id);
		self::assertEmpty($this->service->getAnnotationsForSong($songId));
	}

	public function testServiceMobileSheetsImportAndExport(): void {
		$songId = (int)$this->song->id;

		$dbPath = __DIR__ . '/../../../tests/fixtures/mobilesheets.db';
		self::assertFileExists($dbPath);
		$msDb = new PDO('sqlite:' . $dbPath);
		$msDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Import from MobileSheets into real database
		$imported = $this->service->importFromMobileSheets($msDb, $songId, fileId: 10, clientSongId: 87);
		self::assertCount(11, $imported);

		$stored = $this->service->getAnnotationsForSong($songId);
		self::assertCount(11, $stored);

		// Export back to fresh SQLite database
		$exportDb = new PDO('sqlite::memory:');
		$exportDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		foreach (['AnnotationsBase', 'DrawAnnotations', 'AnnotationPoints', 'TextboxAnnotations'] as $t) {
			$exportDb->exec(MobileSheetsBaselineSchema::TABLES[$t]);
		}

		$this->service->exportToMobileSheets($exportDb, $songId, clientSongId: 87);

		$rows = $exportDb->query('SELECT COUNT(*) FROM AnnotationsBase')->fetchColumn();
		self::assertSame(11, (int)$rows);
	}

	public function testServiceForScoreImportAndExport(): void {
		$songId = (int)$this->song->id;

		$records = [
			[
				'uuid' => 'fs-svc-1',
				'type' => 'text',
				'page' => 1,
				'layer' => 0,
				'rect' => [50, 50, 100, 20],
				'text' => 'Andante',
				'fontSize' => 14,
				'color' => [0, 0, 0, 1],
			],
		];

		$imported = $this->service->importFromForScore($records, $songId, fileId: 10);
		self::assertCount(1, $imported);

		$exported = $this->service->exportToForScore($songId);
		self::assertCount(1, $exported);
		self::assertSame('text', $exported[0]['type']);
		self::assertSame('Andante', $exported[0]['text']);
	}

	public function testFileIdResolutionAndMultiFileIsolation(): void {
		$songId = (int)$this->song->id;

		// 1. Create two separate files for this song
		$fileA = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'sf-uuid-file-a',
			songId: $songId,
			fileId: 1701,
			pageCount: 5,
		));
		$fileB = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'sf-uuid-file-b',
			songId: $songId,
			fileId: 1702,
			pageCount: 3,
		));
		$fileAId = (int)$fileA->id;
		$fileBId = (int)$fileB->id;

		// 2. Save annotation for File A using its song_files.id; verify it normalizes to node fileId (1701)
		$savedA = $this->service->saveAnnotation(new InternalSongAnnotation(
			id: null,
			uuid: 'ann-for-file-a',
			songId: $songId,
			fileId: $fileAId, // Passing song_files.id
			pageNumber: 0,
			layerIndex: 0,
			type: 'drawing',
		));
		self::assertSame(1701, $savedA->fileId, 'Saving with song_files.id should normalize to node fileId');

		// 3. Save annotation for File B using its node fileId (1702)
		$savedB = $this->service->saveAnnotation(new InternalSongAnnotation(
			id: null,
			uuid: 'ann-for-file-b',
			songId: $songId,
			fileId: 1702, // Passing node fileId
			pageNumber: 1,
			layerIndex: 0,
			type: 'highlight',
		));
		self::assertSame(1702, $savedB->fileId);

		// 4. Query File A using node fileId (1701) -> returns ONLY Annotation A
		$resNodeA = $this->service->getAnnotationsForSong($songId, 1701);
		self::assertCount(1, $resNodeA);
		self::assertSame('ann-for-file-a', $resNodeA[0]->uuid);

		// 5. Query File A using song_files.id ($fileAId) -> resolves to 1701 and returns ONLY Annotation A
		$resSongFileA = $this->service->getAnnotationsForSong($songId, $fileAId);
		self::assertCount(1, $resSongFileA);
		self::assertSame('ann-for-file-a', $resSongFileA[0]->uuid);

		// 6. Query File B using node fileId (1702) -> returns ONLY Annotation B
		$resNodeB = $this->service->getAnnotationsForSong($songId, 1702);
		self::assertCount(1, $resNodeB);
		self::assertSame('ann-for-file-b', $resNodeB[0]->uuid);

		// 7. Query File B using song_files.id ($fileBId) -> resolves to 1702 and returns ONLY Annotation B
		$resSongFileB = $this->service->getAnnotationsForSong($songId, $fileBId);
		self::assertCount(1, $resSongFileB);
		self::assertSame('ann-for-file-b', $resSongFileB[0]->uuid);

		// 8. Query without fileId (song-level) -> returns BOTH annotations
		$resAll = $this->service->getAnnotationsForSong($songId);
		self::assertCount(2, $resAll);

		// 9. Query with unknown fileId -> returns empty (maintains strict file isolation)
		$resUnknown = $this->service->getAnnotationsForSong($songId, 99999);
		self::assertEmpty($resUnknown);
	}

	public function testUnassociatedSongAnnotationsFallback(): void {
		$songId = (int)$this->song->id;

		// Save a song-level unassociated annotation (file_id = 0)
		$this->annotationMapper->save(new InternalSongAnnotation(
			id: null,
			uuid: 'ann-global-song',
			songId: $songId,
			fileId: 0,
			pageNumber: 0,
			layerIndex: 0,
			type: 'text',
		));

		// When querying with a fileId that has no file-specific annotations, returns the global annotation
		$byFile = $this->service->getAnnotationsForSong($songId, 12345);
		self::assertCount(1, $byFile);
		self::assertSame('ann-global-song', $byFile[0]->uuid);
	}
}
