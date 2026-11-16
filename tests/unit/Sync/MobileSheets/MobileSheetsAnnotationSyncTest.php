<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsAnnotationAdapter;
use OCA\SheetMusic\Tests\TestSupport\MobileSheetsEndToEndSyncHarness;
use PHPUnit\Framework\TestCase;

final class MobileSheetsAnnotationSyncTest extends TestCase {
	private MobileSheetsEndToEndSyncHarness $harness;

	protected function setUp(): void {
		parent::setUp();
		$this->harness = new MobileSheetsEndToEndSyncHarness();
	}

	protected function tearDown(): void {
		$this->harness->cleanup();
		parent::tearDown();
	}

	public function testAnnotationsTwoWaySyncAndZeroDiff(): void {
		$this->harness->setupEmptyLibraries();
		$this->harness->sync();

		// 1. Client creates a song with a PDF file
		$pdfContent = MobileSheetsEndToEndSyncHarness::generateValidPdf('Concerto in G Minor', 3);
		$songClientId = $this->harness->clientCreateSong('Concerto in G Minor', 'concerto.pdf', $pdfContent);

		// 2. Client adds various annotations (drawing, stamp, text, shape)
		$pdo = $this->harness->openSqlite($this->harness->clientDbPath);

		// Drawing annotation (stroke)
		$ptsDrawing = [
			['x' => 0.2, 'y' => 0.3],
			['x' => 0.25, 'y' => 0.35],
			['x' => 0.3, 'y' => 0.4],
		];
		[$drawBlob, $drawCount] = MobileSheetsAnnotationAdapter::packPointsBlob($ptsDrawing, 612.0, 792.0, 'drawing', 3.0);

		$pdo->exec("INSERT INTO AnnotationsBase (Id, SongId, Page, Type, Opacity, Zoom, ZoomY, Version, SourcePageWidth, SourcePageHeight, Layer)
			VALUES (1, {$songClientId}, 0, 1, 100, 1.0, 1.0, 3, 612.0, 792.0, 0)");
		$pdo->exec('INSERT INTO DrawAnnotations (Id, BaseId, LineColor, FillColor, LineWidth, DrawMode, PenMode, SmoothMode)
			VALUES (1, 1, -65536, 0, 3.0, 0, 1, 0)');
		$stmtPts = $pdo->prepare('INSERT INTO AnnotationPoints (Id, AnnotationId, Points, Count) VALUES (:id, :annId, :pts, :cnt)');
		$stmtPts->execute([':id' => 1, ':annId' => 1, ':pts' => $drawBlob, ':cnt' => $drawCount]);

		// Stamp annotation (musical quarter note)
		[$stampBlob, $stampCount] = MobileSheetsAnnotationAdapter::packPointsBlob([['x' => 0.5, 'y' => 0.6]], 612.0, 792.0, 'stamp');
		$pdo->exec("INSERT INTO AnnotationsBase (Id, SongId, Page, Type, Opacity, Zoom, ZoomY, Version, SourcePageWidth, SourcePageHeight, Layer)
			VALUES (2, {$songClientId}, 0, 3, 100, 1.0, 1.0, 3, 612.0, 792.0, 0)");
		$pdo->exec("INSERT INTO StampAnnotations (Id, BaseId, Type, Size, FilePath, Color, Font, Symbol)
			VALUES (2, 2, 2, 40.0, '', -16777216, 1, 'q')");
		$stmtPts->execute([':id' => 2, ':annId' => 2, ':pts' => $stampBlob, ':cnt' => $stampCount]);

		// Textbox annotation
		[$textBlob, $textCount] = MobileSheetsAnnotationAdapter::packPointsBlob([['x' => 0.1, 'y' => 0.8], ['x' => 0.4, 'y' => 0.85]], 612.0, 792.0, 'text');
		$pdo->exec("INSERT INTO AnnotationsBase (Id, SongId, Page, Type, Opacity, Zoom, ZoomY, Version, SourcePageWidth, SourcePageHeight, Layer)
			VALUES (3, {$songClientId}, 1, 0, 100, 1.0, 1.0, 3, 612.0, 792.0, 0)");
		$pdo->exec("INSERT INTO TextboxAnnotations (Id, BaseId, TextColor, Text, FontFamily, FontSize, FontStyle, FillColor, BorderColor, TextAlign, HasBorder, BorderWidth, AutoSize, LineSpacing)
			VALUES (3, 3, -16777216, 'Play con brio', 0, 16.0, 0, 0, 0, 0, 0, 0.0, 1, 1.0)");
		$stmtPts->execute([':id' => 3, ':annId' => 3, ':pts' => $textBlob, ':cnt' => $textCount]);
		$this->harness->markClientDbDirty();

		// 3. Perform initial sync
		$stats = $this->harness->sync();
		self::assertNotNull($stats);
		self::assertSame(1, $stats['songsCreated']);

		// 4. Verify Nextcloud imported all 3 annotations into canonical storage
		$ncSong = $this->harness->songMapper->findByClientId($this->harness->libraryId, $songClientId);
		self::assertNotNull($ncSong);
		self::assertNotNull($ncSong->id);

		$canonicalAnns = $this->harness->annotationService->getAnnotationsForSong((int)$ncSong->id);
		self::assertCount(3, $canonicalAnns);

		$songFiles = $this->harness->songFileMapper->findAllForSong((int)$ncSong->id);
		self::assertNotEmpty($songFiles);
		$nodeFileId = (int)$songFiles[0]->fileId;
		$songFileTableId = (int)$songFiles[0]->id;
		self::assertGreaterThan(0, $nodeFileId);
		self::assertGreaterThan(0, $songFileTableId);

		// Verify every imported annotation is linked to the correct node fileId
		foreach ($canonicalAnns as $ann) {
			self::assertSame($nodeFileId, $ann->fileId, 'Annotation should be bound to the Nextcloud node fileId');
		}

		// Verify querying by node fileId and by song_files.id both resolve and return all 3 annotations
		$byNodeId = $this->harness->annotationService->getAnnotationsForSong((int)$ncSong->id, $nodeFileId);
		self::assertCount(3, $byNodeId);
		$byTableId = $this->harness->annotationService->getAnnotationsForSong((int)$ncSong->id, $songFileTableId);
		self::assertCount(3, $byTableId);

		$types = array_column(array_map(static fn ($a): array => $a->jsonSerialize(), $canonicalAnns), 'type');
		sort($types);
		self::assertSame(['drawing', 'stamp', 'text'], $types);

		// 5. Verify exported SQLite database has ZERO DIFF with client SQLite database
		self::assertCount(3, $this->harness->getClientTableRows('AnnotationsBase'));
		self::assertCount(1, $this->harness->getClientTableRows('DrawAnnotations'));
		self::assertCount(1, $this->harness->getClientTableRows('StampAnnotations'));
		self::assertCount(1, $this->harness->getClientTableRows('TextboxAnnotations'));
		self::assertCount(3, $this->harness->getClientTableRows('AnnotationPoints'));

		// Verify Points column has BLOB storage class in SQLite, never TEXT
		$ptsDb = $this->harness->openSqlite($this->harness->clientDbPath);
		$ptsTypes = $ptsDb->query('SELECT DISTINCT typeof(Points) FROM AnnotationPoints')->fetchAll(\PDO::FETCH_COLUMN);
		self::assertSame(['blob'], $ptsTypes, 'Points must be stored as binary BLOB in SQLite');

		// 6. Idempotent re-sync: sync again without modifications
		$resyncStats = $this->harness->sync();
		self::assertSame(0, $resyncStats['songsCreated']);
		self::assertSame(0, $resyncStats['songsUpdated']);
		self::assertSame(0, $resyncStats['songsDeleted']);

		// Annotations count must remain exactly 3 on server and client
		$recheckedAnns = $this->harness->annotationService->getAnnotationsForSong((int)$ncSong->id);
		self::assertCount(3, $recheckedAnns);
		self::assertCount(3, $this->harness->getClientTableRows('AnnotationsBase'));

		// 7. Client deletes annotation #2 (stamp) and syncs
		$pdoClient = $this->harness->openSqlite($this->harness->clientDbPath);
		$pdoClient->exec('DELETE FROM AnnotationsBase WHERE Id = 2');
		$pdoClient->exec('DELETE FROM StampAnnotations WHERE BaseId = 2');
		$pdoClient->exec('DELETE FROM AnnotationPoints WHERE AnnotationId = 2');
		$this->harness->markClientDbDirty();

		$this->harness->sync();

		// Server should have reconciled the deletion: only 2 active annotations remain
		$activeAfterDelete = $this->harness->annotationService->getAnnotationsForSong((int)$ncSong->id, includeDeleted: false);
		self::assertCount(2, $activeAfterDelete);
		$activeTypes = array_column(array_map(static fn ($a): array => $a->jsonSerialize(), $activeAfterDelete), 'type');
		sort($activeTypes);
		self::assertSame(['drawing', 'text'], $activeTypes);

		// Client also has exactly 2 annotations in AnnotationsBase
		self::assertCount(2, $this->harness->getClientTableRows('AnnotationsBase'));
		self::assertCount(0, $this->harness->getClientTableRows('StampAnnotations'));

		// 8. Server Web UI adds a new highlight annotation
		$webAnn = new InternalSongAnnotation(
			id: null,
			uuid: 'web-highlight-uuid-999',
			songId: (int)$ncSong->id,
			fileId: (int)$canonicalAnns[0]->fileId,
			pageNumber: 0,
			layerIndex: 0,
			type: 'highlight',
			x: 0.2,
			y: 0.5,
			width: 0.4,
			height: 0.05,
			style: ['stroke' => '#ffff00', 'strokeWidth' => 20.0, 'opacity' => 0.5],
			data: [
				'path' => 'M 200 500 L 600 500',
				'points' => [['x' => 0.2, 'y' => 0.5], ['x' => 0.6, 'y' => 0.5]],
			],
		);
		$this->harness->annotationService->saveAnnotation($webAnn);

		// 9. Sync from server to client
		$this->harness->sync();

		// Client SQLite now has 3 annotations: 2 previous + 1 exported from web
		self::assertCount(3, $this->harness->getClientTableRows('AnnotationsBase'));
		self::assertCount(2, $this->harness->getClientTableRows('DrawAnnotations')); // drawing + highlight

		// 10. Re-sync once more: perfectly idempotent, zero diff
		$finalSyncStats = $this->harness->sync();
		self::assertSame(0, $finalSyncStats['songsCreated']);
		self::assertCount(3, $this->harness->getClientTableRows('AnnotationsBase'));

		// 11. Client renumbers its annotation IDs (e.g. shifts them by +100)
		$pdoShift = $this->harness->openSqlite($this->harness->clientDbPath);
		$pdoShift->exec('UPDATE AnnotationsBase SET Id = Id + 100');
		$pdoShift->exec('UPDATE DrawAnnotations SET BaseId = BaseId + 100, Id = Id + 100');
		$pdoShift->exec('UPDATE TextboxAnnotations SET BaseId = BaseId + 100, Id = Id + 100');
		$pdoShift->exec('UPDATE AnnotationPoints SET AnnotationId = AnnotationId + 100, Id = Id + 100');
		$this->harness->markClientDbDirty();

		$this->harness->sync();

		// Due to fingerprint matching, annotations must NOT be duplicated on the server
		$serverAnnsAfterRenumber = $this->harness->annotationService->getAnnotationsForSong((int)$ncSong->id, includeDeleted: false);
		self::assertCount(3, $serverAnnsAfterRenumber, 'Fingerprint deduplication should prevent duplicate annotations when client renumbers IDs');

		// 12. Storage node migration: filecache node ID updates from old node to newNodeId
		$newNodeId = 99999;
		$songFile = $this->harness->songFileMapper->findAllForSong((int)$ncSong->id)[0];
		self::assertSame($nodeFileId, $songFile->fileId);

		// Update the song file to simulate Nextcloud storage node replacement
		$songFile->fileId = $newNodeId;
		$this->harness->songFileMapper->update($songFile);

		// Querying by the new node ID should automatically resolve or heal annotations to newNodeId
		$healedAnns = $this->harness->annotationService->getAnnotationsForSong((int)$ncSong->id, $newNodeId);
		self::assertCount(3, $healedAnns, 'Annotations should be returned and healed to the new node fileId');
		foreach ($healedAnns as $ha) {
			self::assertSame($newNodeId, $ha->fileId);
		}
	}
}
