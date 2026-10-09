<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\Controller\EntityController;
use OCA\MobileSheetsManager\Service\EntityService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class EntityControllerTest extends TestCase {
	public function testCreateEndpointsValidateParams(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('');

		$controller = new EntityController(
			'mobilesheetsmanager',
			$request,
			$this->createMock(EntityService::class),
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createComposer(null, 'Bach')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createComposer(1, '  ')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createBook(1, null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createCollection(null, 'Jazz')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createArtist(1, '')->getStatus());
	}

	public function testCreateComposerDelegatesToService(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$entityService = $this->createMock(EntityService::class);
		$entityService->expects($this->once())
			->method('createEntity')
			->with($user, 1, 'composer', 'Bach')
			->willReturn(['id' => 10, 'name' => 'Bach']);

		$controller = new EntityController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$entityService,
			$userSession,
		);

		$res = $controller->createComposer(1, 'Bach');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$this->assertSame(['id' => 10, 'name' => 'Bach'], $res->getData());
	}

	public function testDeleteEndpointsValidateParamsAndDelegate(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$entityService = $this->createMock(EntityService::class);
		$entityService->expects($this->once())
			->method('deleteEntity')
			->with($user, 1, 'composers', 10)
			->willReturn(['songs' => []]);

		$controller = new EntityController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$entityService,
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->deleteComposer(10, null)->getStatus());

		$res = $controller->deleteComposer(10, 1);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['songs' => []], $res->getData());
	}

	public function testMergeEntitiesValidatesParams(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn(string $k) => match ($k) {
			'libraryId' => 0,
			'entityType' => '',
			'keepId' => 0,
			'duplicateIds' => [],
			default => null,
		});

		$controller = new EntityController(
			'mobilesheetsmanager',
			$request,
			$this->createMock(EntityService::class),
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->mergeEntities(null, null, null, null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->mergeEntities(1, 'composers', 5, [5])->getStatus()); // duplicateIds == keepId
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->mergeEntities(1, '', 5, [6])->getStatus());
	}

	public function testMergeEntitiesDelegatesToService(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$entityService = $this->createMock(EntityService::class);
		$entityService->expects($this->once())
			->method('mergeEntities')
			->with($user, 1, 'composers', 50, [5])
			->willReturn(['mergedCount' => 1, 'libraryData' => []]);

		$controller = new EntityController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$entityService,
			$userSession,
		);

		$res = $controller->mergeEntities(1, 'composers', 50, [5]);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['mergedCount' => 1, 'libraryData' => []], $res->getData());
	}
}

