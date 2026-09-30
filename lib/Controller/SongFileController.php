<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Service\SongFileService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class SongFileController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SongFileService $songFileService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/import-metadata')]
	public function importSongMetadata(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$fileInfo = $this->request->getUploadedFile('file');
		if ($fileInfo === null && isset($_FILES['file'])) {
			$fileInfo = $_FILES['file'];
		}

		$path = (string)$this->request->getParam('path', '');

		try {
			$data = $this->songFileService->importSongMetadata($fileInfo, $path, $user);
			return new DataResponse($data);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => 'Could not read file content'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => 'Could not read the PDF metadata'], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/upload')]
	public function uploadSongFile(int $id, ?int $libraryId = null): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$fileInfo = $this->request->getUploadedFile('file');
		if ($fileInfo === null && isset($_FILES['file'])) {
			$fileInfo = $_FILES['file'];
		}

		try {
			$result = $this->songFileService->uploadSongFile($user, $libraryId, $id, $fileInfo);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/attach')]
	public function attachNextcloudFile(
		int $id,
		?int $libraryId = null,
		?string $sourcePath = null,
		string $action = 'copy',
	): DataResponse {
		$sourcePath = $sourcePath ?? (string)$this->request->getParam('sourcePath', '');
		$action = (string)$this->request->getParam('action', $action);

		if ($libraryId === null || $sourcePath === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songFileService->attachNextcloudFile($user, $libraryId, $id, $sourcePath, $action);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/delete')]
	public function deleteSongFile(int $id, int $fileId, ?int $libraryId = null): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songFileService->deleteSongFile($user, $libraryId, $id, $fileId);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}

