<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Tests\Unit\Controller;

use OCA\SheetMusic\Controller\PageController;
use OCA\SheetMusic\Service\AssetVersionService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PageControllerTest extends TestCase {
	public function testIndexPassesAssetVersionAndSetsNoCacheHeader(): void {
		$assetVersionService = new AssetVersionService();
		$controller = new PageController(
			'sheetmusic',
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IURLGenerator::class),
			$assetVersionService,
		);

		$response = $controller->index();
		$params = $response->getParams();
		$headers = $response->getHeaders();
		$expectedMeta = $assetVersionService->getMetadata();

		self::assertSame($expectedMeta['assetVersion'], $params['assetVersion'] ?? null);
		self::assertSame($expectedMeta['appVersion'], $params['appVersion'] ?? null);
		self::assertSame($expectedMeta['releaseDate'] ?? '', $params['releaseDate'] ?? null);
		self::assertSame($expectedMeta['buildDate'] ?? '', $params['buildDate'] ?? null);
		self::assertSame('no-cache, no-store, must-revalidate', $headers['Cache-Control'] ?? null);
	}

	public function testAppVersionReturnsMetadataWithNoStoreHeader(): void {
		$assetVersionService = new AssetVersionService();
		$controller = new PageController(
			'sheetmusic',
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IURLGenerator::class),
			$assetVersionService,
		);

		$response = $controller->appVersion();
		$data = $response->getData();
		$headers = $response->getHeaders();

		self::assertSame($assetVersionService->getMetadata(), $data);
		self::assertSame('no-store, no-cache', $headers['Cache-Control'] ?? null);
	}

	public function testServiceWorkerInjectsAssetVersionAndNoCacheHeader(): void {
		$assetVersionService = new AssetVersionService();
		$expectedVersion = $assetVersionService->getVersion();

		$controller = new PageController(
			'sheetmusic',
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IURLGenerator::class),
			$assetVersionService,
		);

		$response = $controller->serviceWorker();
		$body = (string)$response->getData();
		$headers = $response->getHeaders();

		self::assertStringContainsString("const APP_VERSION = '{$expectedVersion}'", $body);
		self::assertStringNotContainsString('__APP_VERSION__', $body);
		self::assertSame('no-cache', $headers['Cache-Control'] ?? null);
		self::assertSame('/', $headers['Service-Worker-Allowed'] ?? null);
	}
}
