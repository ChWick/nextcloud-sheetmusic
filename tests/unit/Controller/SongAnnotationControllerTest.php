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
use OCA\SheetMusic\Service\SetlistService;
use OCA\SheetMusic\Service\SongAnnotationService;
use OCP\AppFramework\Http;
use OCP\IConfig;
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

		// 2. Save annotation passing canonical fileId ($nodeFileId)
		$this->request->setParam('uuid', 'web-ann-uuid-1');
		$this->request->setParam('fileId', $nodeFileId);
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

		// 4. Query without fileId
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

	public function testSetlistFileAnnotationLifecycle(): void {
		$ocFileId = 98765;
		$setlistService = $this->createMock(SetlistService::class);
		$setlistService->method('resolveSetlistFileOcId')
			->willReturnCallback(static fn (IUser $user, int $libId, int $setlistId, int $fileId, bool $requireWrite) => ($libId === 1 && $setlistId === 10 && $fileId === 5) ? $ocFileId : null);

		$annotationService = new SongAnnotationService(
			$this->annotationMapper,
			new InternalAnnotationSyncMapper($this->dbConn),
			songFileMapper: $this->songFileMapper,
		);
		$controller = new SongAnnotationController(
			'sheetmusic',
			$this->request,
			$annotationService,
			$this->userSession,
			$setlistService,
		);

		$resEmpty = $controller->getSetlistFileAnnotations(1, 10, 5);
		self::assertSame(Http::STATUS_OK, $resEmpty->getStatus());
		self::assertEmpty($resEmpty->getData());

		$this->request->setParam('uuid', 'setlist-ann-1');
		$this->request->setParam('pageNumber', 0);
		$this->request->setParam('layerIndex', 0);
		$this->request->setParam('type', 'drawing');
		$this->request->setParam('x', 0.1);
		$this->request->setParam('y', 0.2);
		$this->request->setParam('width', 0.3);
		$this->request->setParam('height', 0.4);
		$this->request->setParam('style', ['color' => '#e53935']);
		$this->request->setParam('data', ['points' => [[0.1, 0.2], [0.4, 0.6]]]);

		$resSave = $controller->saveSetlistFileAnnotation(1, 10, 5);
		self::assertSame(Http::STATUS_OK, $resSave->getStatus());
		/** @var InternalSongAnnotation $saved */
		$saved = $resSave->getData();
		self::assertSame(0, $saved->songId);
		self::assertSame($ocFileId, $saved->fileId);

		$resList = $controller->getSetlistFileAnnotations(1, 10, 5);
		self::assertSame(Http::STATUS_OK, $resList->getStatus());
		self::assertCount(1, $resList->getData());
		self::assertSame('setlist-ann-1', $resList->getData()[0]->uuid);

		$this->request->setParam('upsert', []);
		$this->request->setParam('deleteIds', [$saved->id]);
		$resBatch = $controller->batchSetlistFileAnnotations(1, 10, 5);
		self::assertSame(Http::STATUS_OK, $resBatch->getStatus());
		self::assertSame([$saved->id], $resBatch->getData()['deletedIds']);

		$resAfter = $controller->getSetlistFileAnnotations(1, 10, 5);
		self::assertEmpty($resAfter->getData());
	}

	public function testFileMusicFontGetAndSave(): void {
		$store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			});
		$config->method('setAppValue')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$store): void {
				$store[$key] = $value;
			});

		$controller = new SongAnnotationController(
			'sheetmusic',
			$this->request,
			$this->annotationService,
			$this->userSession,
			null,
			null,
			$config,
		);

		// Default is bravura
		$resDefault = $controller->getFileMusicFont(4455);
		self::assertSame(Http::STATUS_OK, $resDefault->getStatus());
		self::assertSame(['font' => 'bravura'], $resDefault->getData());

		// Invalid font rejected
		$this->request->setParam('font', 'comic-sans');
		$resInvalid = $controller->saveFileMusicFont(4455);
		self::assertSame(Http::STATUS_BAD_REQUEST, $resInvalid->getStatus());

		// Valid font saved and persisted
		$this->request->setParam('font', 'petaluma');
		$resSave = $controller->saveFileMusicFont(4455);
		self::assertSame(Http::STATUS_OK, $resSave->getStatus());
		self::assertSame(['font' => 'petaluma'], $resSave->getData());

		$resGet = $controller->getFileMusicFont(4455);
		self::assertSame(['font' => 'petaluma'], $resGet->getData());

		// Unauthorized save rejected
		$this->userSession->setUser(null);
		$resUnauth = $controller->saveFileMusicFont(4455);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $resUnauth->getStatus());
	}

	public function testHandwritingModelLifecycle(): void {
		$userStore = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default = '') use (&$userStore): string {
				return $userStore["$uid:$key"] ?? $default;
			});
		$config->method('setUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$userStore): void {
				$userStore["$uid:$key"] = $value;
			});
		$config->method('deleteUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key) use (&$userStore): void {
				unset($userStore["$uid:$key"]);
			});

		$controller = new SongAnnotationController(
			'sheetmusic',
			$this->request,
			$this->annotationService,
			$this->userSession,
			null,
			null,
			$config,
		);

		// Initially empty
		$resEmpty = $controller->getHandwritingModel();
		self::assertSame(Http::STATUS_OK, $resEmpty->getStatus());
		self::assertSame(['samples' => []], $resEmpty->getData());

		// Save valid + invalid entries; sanitizer keeps only valid ones
		$this->request->setParam('samples', [
			[
				'symbol' => "\u{ED11}",
				'points' => [['x' => 0.123456, 'y' => -0.25, 'tx' => 0, 'ty' => 1, 'strokeId' => 0]],
				'strokeCount' => 1,
				'aspectRatio' => 0.2,
				'weight' => 1.5,
				'updatedAt' => 12345,
			],
			['symbol' => '', 'points' => []],
		]);
		$resSave = $controller->saveHandwritingModel();
		self::assertSame(Http::STATUS_OK, $resSave->getStatus());
		self::assertSame(['saved' => 1], $resSave->getData());

		$resGet = $controller->getHandwritingModel();
		self::assertCount(1, $resGet->getData()['samples']);
		self::assertSame("\u{ED11}", $resGet->getData()['samples'][0]['symbol']);

		// Delete clears stored model
		$resDel = $controller->deleteHandwritingModel();
		self::assertSame(Http::STATUS_OK, $resDel->getStatus());
		self::assertSame(['samples' => []], $controller->getHandwritingModel()->getData());
	}

	public function testFileAnnotationLayersGetAndSave(): void {
		$store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			});
		$config->method('setAppValue')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$store): void {
				$store[$key] = $value;
			});

		$controller = new SongAnnotationController(
			'sheetmusic',
			$this->request,
			$this->annotationService,
			$this->userSession,
			null,
			null,
			$config,
		);

		// Default has single Default layer
		$resDefault = $controller->getFileAnnotationLayers(4455);
		self::assertSame(Http::STATUS_OK, $resDefault->getStatus());
		self::assertSame([
			['id' => 0, 'name' => 'Default', 'visible' => true, 'order' => 0],
		], $resDefault->getData()['layers']);

		// Save custom layers (even if default omitted, sanitizer restores id 0 and normalizes order)
		$this->request->setParam('layers', [
			['id' => 2, 'name' => 'Organ', 'visible' => false, 'order' => 5],
			['id' => 1, 'name' => 'Fingerings', 'visible' => true, 'order' => 1],
		]);
		$resSave = $controller->saveFileAnnotationLayers(4455);
		self::assertSame(Http::STATUS_OK, $resSave->getStatus());
		self::assertSame([
			['id' => 0, 'name' => 'Default', 'visible' => true, 'order' => 0],
			['id' => 1, 'name' => 'Fingerings', 'visible' => true, 'order' => 1],
			['id' => 2, 'name' => 'Organ', 'visible' => false, 'order' => 2],
		], $resSave->getData()['layers']);

		$resGet = $controller->getFileAnnotationLayers(4455);
		self::assertCount(3, $resGet->getData()['layers']);

		// Unauthorized save rejected
		$this->userSession->setUser(null);
		$resUnauth = $controller->saveFileAnnotationLayers(4455);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $resUnauth->getStatus());
	}
}
