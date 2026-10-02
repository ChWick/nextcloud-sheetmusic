<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class LibraryController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private LibraryService $libraryService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries')]
	public function getLibraries(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse(['libraries' => $this->libraryService->getLibraries($user->getUID())]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries')]
	public function addLibrary(string $path): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->libraryService->addLibrary($user->getUID(), $path);
		if ($result === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($result, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/libraries/{id}')]
	public function deleteLibrary(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->libraryService->deleteLibrary($user->getUID(), $id);
		if ($result === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($result);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/sync-library')]
	public function syncLibrary(int $libraryId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->libraryService->syncLibrary($libraryId, $user);
			return new DataResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new DataResponse(
				['message' => "Error syncing library ID $libraryId: " . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}
}

