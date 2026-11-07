<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Controller;

use OCA\SheetMusic\Controller\SongAnnotationController;
use OCA\SheetMusic\Db\InternalAnnotationSyncMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Service\SongAnnotationService;
use OCP\AppFramework\Http;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use TestSupport\FakeRequest;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;

final class SongAnnotationControllerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private IUser $user;
	private FakeUserSession $userSession;
	private FakeRequest $request;
	private SongAnnotationService $annotationService;
	private SongAnnotationController $controller;
	private InternalSong $song;
	private InternalSongFile $songFile;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('SongAnnotationController Test Lib');
		$this->user = $this->userManager->get($library->userId);
		$this->userSession = new FakeUserSession($this->user);
		$this->request = new FakeRequest();

		$syncMapper = new InternalAnnotationSyncMapper($this->dbConn);
		$this->annotationService = new SongAnnotationService(
			$this->annotationMapper,
			$syncMapper,
			songFileMapper: $this->songFileMapper,
		);

		$this->controller = new SongAnnotationController(
			'sheetmusic',
			$this->request,
			$this->annotationService,
			$this->userSession,
		);

		$this->song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'ctrl-test-song-uuid',
			libraryId: (int)$library->id,
			title: 'Pavane pour une infante défunte',
		));

		$this->songFile = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'ctrl-test-sf-uuid',
			songId: (int)$this->song->id,
			fileId: 4455,
			pageCount: 4,
		));
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testGetAnnotationsUnauthorized(): void {
		$this->userSession->setUser(null);
		$res = $this->controller->getAnnotations((int)$this->song->id);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $res->getStatus());
	}

	public function testSaveAnnotationUnauthorized(): void {
		$this->userSession->setUser(null);
		$res = $this->controller->saveAnnotation((int)$this->song->id);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $res->getStatus());
	}

	public function testDeleteAnnotationUnauthorized(): void {
		$this->userSession->setUser(null);
		$res = $this->controller->deleteAnnotation(1);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $res->getStatus());
	}

	public function testGetAndSaveAnnotationsWithCorrectIds(): void {
		$songId = (int)$this->song->id;
		$songFileId = (int)$this->songFile->id;
		$nodeFileId = 4455;

		// 1. Initially empty
		$resEmpty = $this->controller->getAnnotations($songId, $nodeFileId);
		self::assertSame(Http::STATUS_OK, $resEmpty->getStatus());
		self::assertEmpty($resEmpty->getData());

		// 2. Save annotation passing song_files.id ($songFileId)
		$this->request->setParam('uuid', 'web-ann-uuid-1');
		$this->request->setParam('fileId', $songFileId);
		$this->request->setParam('pageNumber', 1);
		$this->request->setParam('layerIndex', 0);
		$this->request->setParam('type', 'drawing');
		$this->request->setParam('x', 0.2);
		$this->request->setParam('y', 0.3);
		$this->request->setParam('width', 0.4);
		$this->request->setParam('height', 0.5);
		$this->request->setParam('style', ['stroke' => '#ff0000', 'strokeWidth' => 3]);
		$this->request->setParam('data', ['path' => 'M 10 20 L 30 40']);

		$resSave = $this->controller->saveAnnotation($songId);
		self::assertSame(Http::STATUS_OK, $resSave->getStatus());
		/** @var InternalSongAnnotation $saved */
		$saved = $resSave->getData();
		self::assertInstanceOf(InternalSongAnnotation::class, $saved);
		self::assertNotNull($saved->id);
		// Normalizes to node file ID (4455)
		self::assertSame($nodeFileId, $saved->fileId);
		self::assertSame($songId, $saved->songId);

		// 3. Query via node fileId
		$resByNode = $this->controller->getAnnotations($songId, $nodeFileId);
		self::assertSame(Http::STATUS_OK, $resByNode->getStatus());
		self::assertCount(1, $resByNode->getData());
		self::assertSame('web-ann-uuid-1', $resByNode->getData()[0]->uuid);

		// 4. Query via song_files.id
		$resBySongFile = $this->controller->getAnnotations($songId, $songFileId);
		self::assertSame(Http::STATUS_OK, $resBySongFile->getStatus());
		self::assertCount(1, $resBySongFile->getData());
		self::assertSame('web-ann-uuid-1', $resBySongFile->getData()[0]->uuid);

		// 5. Query without fileId
		$resAll = $this->controller->getAnnotations($songId);
		self::assertSame(Http::STATUS_OK, $resAll->getStatus());
		self::assertCount(1, $resAll->getData());

		// 6. Delete annotation
		$resDel = $this->controller->deleteAnnotation((int)$saved->id);
		self::assertSame(Http::STATUS_OK, $resDel->getStatus());
		self::assertSame(['success' => true], $resDel->getData());

		// 7. Verify deletion
		$resAfterDel = $this->controller->getAnnotations($songId, $nodeFileId);
		self::assertEmpty($resAfterDel->getData());
	}
}
