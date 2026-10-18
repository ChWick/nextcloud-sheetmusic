<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
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
		private LibraryIntegrityService $integrityService,
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
	public function addLibrary(string $path, bool $importExistingDb = true): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$import = (bool)$this->request->getParam('importExistingDb', $this->request->getParam('import_mobilesheets', $importExistingDb));
		try {
			$result = $this->libraryService->addLibrary($user->getUID(), $path, $import);
			if ($result === null) {
				return new DataResponse(['message' => 'Invalid library path'], Http::STATUS_BAD_REQUEST);
			}

			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
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
	#[ApiRoute(verb: 'PUT', url: '/api/libraries/{id}/settings')]
	public function updateSettings(int $id, ?bool $autoPaths = null, ?bool $autoPdfMetadata = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->libraryService->updateSettings($user->getUID(), $id, $autoPaths, $autoPdfMetadata);
		if ($result === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['library' => $result]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{id}/integrity')]
	public function getIntegrity(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$issues = $this->integrityService->checkIntegrity($id, $user->getUID());

			return new DataResponse(['issues' => array_map(static fn ($issue) => $issue->jsonSerialize(), $issues)], Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new DataResponse(
				['message' => 'Error auditing library integrity: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/integrity/fix')]
	public function fixIntegrityIssue(
		int $id,
		string $issueType,
		?int $songId = null,
		?int $fileId = null,
		?string $entityType = null,
		?int $entityId = null,
		?string $path = null,
	): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->integrityService->fixIssue($id, $user->getUID(), $issueType, $songId, $fileId, $entityType, $entityId, $path);

			return new DataResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new DataResponse(
				['success' => false, 'message' => 'Error fixing integrity issue: ' . $e->getMessage()],
				Http::STATUS_OK
			);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/integrity/fix-all')]
	public function fixAllIntegrityIssues(int $id, ?string $issueType = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->integrityService->fixAllIssues($id, $user->getUID(), $issueType);

			return new DataResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new DataResponse(
				['success' => false, 'message' => 'Error batch-fixing integrity issues: ' . $e->getMessage()],
				Http::STATUS_OK
			);
		}
	}
}
