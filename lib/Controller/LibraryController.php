<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Exception\SecurityException;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\LibraryShareService;
use OCA\SheetMusic\Service\LibraryToolService;
use OCA\SheetMusic\Service\SublibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IShare;

class LibraryController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private LibraryService $libraryService,
		private IUserSession $userSession,
		private LibraryIntegrityService $integrityService,
		private ?LibraryToolService $toolService = null,
		private ?SublibraryService $sublibraryService = null,
		private ?LibraryShareService $shareService = null,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries')]
	public function getLibraries(): DataResponse {
		$user = $this->userSession->getUser();
		$publicToken = $this->request->getHeader('X-Public-Share-Token') ?? $this->request->getParam('token');

		if ($publicToken !== null && $publicToken !== '') {
			$info = $this->shareService?->findLibraryByPublicToken($publicToken);
			if ($info !== null) {
				return new DataResponse(['libraries' => [$info['library']]]);
			}
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}

		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse(['libraries' => $this->libraryService->getLibraries($user->getUID())]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries')]
	public function addLibrary(
		string $path,
		bool $importExistingDb = true,
		?string $progressId = null,
		bool $importPdfMetadata = true,
	): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$import = (bool)$this->request->getParam('importExistingDb', $this->request->getParam('import_mobilesheets', $importExistingDb));
		$progId = $this->resolveProgressId($progressId);
		$importPdfMeta = filter_var(
			$this->request->getParam('importPdfMetadata', $this->request->getParam('import_pdf_metadata', $importPdfMetadata)),
			FILTER_VALIDATE_BOOLEAN,
			FILTER_NULL_ON_FAILURE
		) ?? true;

		try {
			$result = $this->libraryService->addLibrary($user->getUID(), $path, $import, $progId, $importPdfMeta);
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
	#[ApiRoute(verb: 'GET', url: '/api/libraries/progress/{progressId}')]
	public function getProgress(string $progressId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$progress = $this->libraryService->getProgress($progressId);
		return $progress === null
			? new DataResponse(['message' => 'Progress not found'], Http::STATUS_NOT_FOUND)
			: new DataResponse($progress);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/libraries/{id}')]
	public function deleteLibrary(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->libraryService->deleteLibrary($user->getUID(), $id);
		return $result === null
			? new DataResponse([], Http::STATUS_NOT_FOUND)
			: new DataResponse($result);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/libraries/{id}/settings')]
	public function updateSettings(int $id, ?bool $autoPaths = null, ?bool $autoPdfMetadata = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->libraryService->updateSettings($user->getUID(), $id, $autoPaths, $autoPdfMetadata);
		return $result === null
			? new DataResponse([], Http::STATUS_NOT_FOUND)
			: new DataResponse(['library' => $result]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{id}/integrity')]
	public function getIntegrity(int $id, bool|string $autoMatchOrphans = true): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$matchOrphans = filter_var($autoMatchOrphans, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

		try {
			$issues = $this->integrityService->checkIntegrity($id, $user->getUID(), $matchOrphans);

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
	public function fixAllIntegrityIssues(int $id, ?string $issueType = null, ?string $progressId = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$progId = $this->resolveProgressId($progressId);
		if ($progId !== null) {
			$this->libraryService->startProgress($progId, 'Starting integrity fixes...');
		}

		$onProgress = null;
		if ($progId !== null) {
			$onProgress = function (string $stage, int $current, int $total, ?string $detail = null) use ($progId): void {
				$this->libraryService->updateProgress($progId, $stage, $current, $total, $detail ?? '');
			};
		}

		try {
			$result = $this->integrityService->fixAllIssues($id, $user->getUID(), $issueType, $onProgress);
			if ($progId !== null) {
				$this->libraryService->completeProgress($progId, $result['message'] ?? 'Fix complete');
			}

			return new DataResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			if ($progId !== null) {
				$this->libraryService->failProgress($progId, $e->getMessage());
			}
			return new DataResponse(
				['success' => false, 'message' => 'Error batch-fixing integrity issues: ' . $e->getMessage()],
				Http::STATUS_OK
			);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{id}/tools/page-order-audit')]
	public function auditPageOrders(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->toolService === null) {
			return new DataResponse(['message' => 'Tool service unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$result = $this->toolService->auditPageOrders($id, $user);
			return new DataResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new DataResponse(
				['message' => 'Error auditing page orders: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/tools/reset-page-orders')]
	public function resetPageOrders(
		int $id,
		?array $fileIds = null,
		string $mode = 'stuck',
		?string $progressId = null,
	): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->toolService === null) {
			return new DataResponse(['message' => 'Tool service unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$progId = $this->resolveProgressId($progressId);
		if ($progId !== null) {
			$this->libraryService->startProgress($progId, 'Starting page order reset…');
		}

		$onProgress = null;
		if ($progId !== null) {
			$onProgress = function (string $stage, int $current, int $total, ?string $detail = null) use ($progId): void {
				$this->libraryService->updateProgress($progId, $stage, $current, $total, $detail ?? '');
			};
		}

		try {
			$result = $this->toolService->resetPageOrders($id, $user, $fileIds, $mode, $onProgress);
			if ($progId !== null) {
				$this->libraryService->completeProgress($progId, $result['message'] ?? 'Page order reset complete');
			}
			return new DataResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			if ($progId !== null) {
				$this->libraryService->failProgress($progId, $e->getMessage());
			}
			return new DataResponse(
				['success' => false, 'message' => 'Error resetting page orders: ' . $e->getMessage()],
				Http::STATUS_OK
			);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{id}/sublibraries')]
	public function getSublibraries(int $id): DataResponse {
		$user = $this->userSession->getUser();
		$publicToken = $this->request->getHeader('X-Public-Share-Token') ?? $this->request->getParam('token');

		if ($publicToken !== null && $publicToken !== '') {
			$info = $this->shareService?->findLibraryByPublicToken($publicToken);
			if ($info === null || (int)$info['library']->id !== $id) {
				return new DataResponse([], Http::STATUS_FORBIDDEN);
			}
			return new DataResponse([
				'sublibraries' => $this->sublibraryService?->getSublibraries($id) ?? [],
			]);
		}

		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->sublibraryService === null) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse([
			'sublibraries' => $this->sublibraryService->getSublibraries($id),
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/sublibraries')]
	public function createSublibrary(int $id, string $name, string $slug = '', array $filters = []): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->sublibraryService === null) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$filters = (array)$this->request->getParam('filters', $filters);
		$sublib = $this->sublibraryService->createSublibrary($id, $name, $slug, $filters);

		return new DataResponse($sublib, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/libraries/{id}/sublibraries/{subId}')]
	public function updateSublibrary(int $id, int $subId, string $name, string $slug = '', array $filters = []): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->sublibraryService === null) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$filters = (array)$this->request->getParam('filters', $filters);
		$sublib = $this->sublibraryService->updateSublibrary($subId, $name, $slug, $filters);

		return new DataResponse($sublib, Http::STATUS_OK);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/libraries/{id}/sublibraries/{subId}')]
	public function deleteSublibrary(int $id, int $subId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->sublibraryService === null) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$success = $this->sublibraryService->deleteSublibrary($subId);

		return new DataResponse(['success' => $success], Http::STATUS_OK);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{id}/shares')]
	public function getShares(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->shareService === null) {
			return new DataResponse(['message' => 'Share service not available'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$shares = $this->shareService->getShares($id, $user->getUID());
			return new DataResponse(['shares' => $shares]);
		} catch (SecurityException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function parseShareType(mixed $rawShareType): int {
		return match ($rawShareType) {
			'user', IShare::TYPE_USER, (string)IShare::TYPE_USER => IShare::TYPE_USER,
			'group', IShare::TYPE_GROUP, (string)IShare::TYPE_GROUP => IShare::TYPE_GROUP,
			'link', 'public', IShare::TYPE_LINK, (string)IShare::TYPE_LINK => IShare::TYPE_LINK,
			default => is_numeric($rawShareType) ? (int)$rawShareType : IShare::TYPE_USER,
		};
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/shares')]
	public function createShare(
		int $id,
		string $shareWith = '',
		int|string $shareType = IShare::TYPE_USER,
		string $permission = 'read',
		?string $password = null,
	): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->shareService === null) {
			return new DataResponse(['message' => 'Share service not available'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$shareWith = (string)$this->request->getParam('shareWith', $shareWith);
		$parsedShareType = $this->parseShareType($this->request->getParam('shareType', $shareType));
		$permission = (string)$this->request->getParam('permission', $permission);
		$password = $this->request->getParam('password', $password);

		try {
			$share = $this->shareService->createShare(
				$id,
				$user->getUID(),
				$parsedShareType,
				$shareWith,
				$permission,
				$password,
			);
			return new DataResponse($share, Http::STATUS_CREATED);
		} catch (SecurityException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/libraries/{id}/shares/{shareId}')]
	public function updateShare(int $id, string $shareId, string $permission = 'read'): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->shareService === null) {
			return new DataResponse(['message' => 'Share service not available'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$permission = (string)$this->request->getParam('permission', $permission);

		try {
			$share = $this->shareService->updateShare($id, $user->getUID(), $shareId, $permission);
			return new DataResponse($share, Http::STATUS_OK);
		} catch (SecurityException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/libraries/{id}/shares/{shareId}')]
	public function deleteShare(int $id, string $shareId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->shareService === null) {
			return new DataResponse(['message' => 'Share service not available'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$this->shareService->deleteShare($id, $user->getUID(), $shareId);
			return new DataResponse(['success' => true], Http::STATUS_OK);
		} catch (SecurityException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{id}/sublibraries/{subId}/shares')]
	public function getSublibraryShares(int $id, int $subId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->shareService === null) {
			return new DataResponse(['message' => 'Share service not available'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$shares = $this->shareService->getSublibraryShares($id, $subId, $user->getUID());
			return new DataResponse(['shares' => $shares]);
		} catch (SecurityException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/sublibraries/{subId}/shares')]
	public function createSublibraryShare(
		int $id,
		int $subId,
		string $shareWith = '',
		int|string $shareType = IShare::TYPE_USER,
		string $permission = 'read',
		?string $password = null,
	): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->shareService === null) {
			return new DataResponse(['message' => 'Share service not available'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$shareWith = (string)$this->request->getParam('shareWith', $shareWith);
		$parsedShareType = $this->parseShareType($this->request->getParam('shareType', $shareType));
		$permission = (string)$this->request->getParam('permission', $permission);
		$password = $this->request->getParam('password', $password);

		try {
			$share = $this->shareService->createSublibraryShare(
				$id,
				$subId,
				$user->getUID(),
				$parsedShareType,
				$shareWith,
				$permission,
				$password,
			);
			return new DataResponse($share, Http::STATUS_CREATED);
		} catch (SecurityException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/libraries/{id}/sublibraries/{subId}/shares/{shareId}')]
	public function updateSublibraryShare(int $id, int $subId, string $shareId, string $permission = 'read'): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->shareService === null) {
			return new DataResponse(['message' => 'Share service not available'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$permission = (string)$this->request->getParam('permission', $permission);

		try {
			$share = $this->shareService->updateSublibraryShare($id, $subId, $user->getUID(), $shareId, $permission);
			return new DataResponse($share, Http::STATUS_OK);
		} catch (SecurityException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/libraries/{id}/sublibraries/{subId}/shares/{shareId}')]
	public function deleteSublibraryShare(int $id, int $subId, string $shareId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->shareService === null) {
			return new DataResponse(['message' => 'Share service not available'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$this->shareService->deleteSublibraryShare($id, $subId, $user->getUID(), $shareId);
			return new DataResponse(['success' => true], Http::STATUS_OK);
		} catch (SecurityException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function resolveProgressId(?string $progressId): ?string {
		$progId = $progressId ?? $this->request->getParam('progressId');
		if ($progId !== null && session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		return $progId;
	}
}
