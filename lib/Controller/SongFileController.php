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
		$libraryId = $libraryId ?? ((int)$this->request->getParam('libraryId', 0) ?: null);
		if ($libraryId === null) {
			return new DataResponse(['message' => 'libraryId parameter is required'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$fileInfo = $this->request->getUploadedFile('file');
		if ($fileInfo === null && isset($_FILES['file'])) {
			$fileInfo = $_FILES['file'];
		}

		if (isset($fileInfo['error']) && (int)$fileInfo['error'] !== UPLOAD_ERR_OK) {
			[$message, $status] = $this->uploadError((int)$fileInfo['error']);
			return new DataResponse(['message' => $message], $status);
		}
		if ($fileInfo === null && $this->requestExceedsPostMaxSize()) {
			return new DataResponse([
				'message' => sprintf(
					'The request exceeds PHP post_max_size (%s). Increase post_max_size on the server.',
					(string)ini_get('post_max_size'),
				),
			], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
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

	/**
	 * @return array{string, int}
	 */
	private function uploadError(int $error): array {
		return match ($error) {
			UPLOAD_ERR_INI_SIZE => [
				sprintf(
					'The uploaded file exceeds PHP upload_max_filesize (%s). Increase upload_max_filesize and post_max_size on the server.',
					(string)ini_get('upload_max_filesize'),
				),
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			],
			UPLOAD_ERR_FORM_SIZE => [
				'The uploaded file exceeds the maximum size allowed by the upload form.',
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			],
			UPLOAD_ERR_PARTIAL => ['The uploaded file was only partially received. Please retry the upload.', Http::STATUS_BAD_REQUEST],
			UPLOAD_ERR_NO_FILE => ['No file was included in the upload request.', Http::STATUS_BAD_REQUEST],
			UPLOAD_ERR_NO_TMP_DIR => ['The server is missing its configured temporary upload directory.', Http::STATUS_INTERNAL_SERVER_ERROR],
			UPLOAD_ERR_CANT_WRITE => ['The server could not write the uploaded file to disk.', Http::STATUS_INTERNAL_SERVER_ERROR],
			UPLOAD_ERR_EXTENSION => ['A PHP extension stopped the upload.', Http::STATUS_INTERNAL_SERVER_ERROR],
			default => ['The upload failed with an unknown PHP upload error.', Http::STATUS_BAD_REQUEST],
		};
	}

	private function requestExceedsPostMaxSize(): bool {
		$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
		$postMaxSize = (string)ini_get('post_max_size');
		if ($contentLength <= 0 || trim($postMaxSize) === '' || trim($postMaxSize) === '0') {
			return false;
		}

		if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMG]?)B?\s*$/i', $postMaxSize, $matches)) {
			return false;
		}

		$power = match (strtoupper($matches[2])) {
			'K' => 1,
			'M' => 2,
			'G' => 3,
			default => 0,
		};
		$limit = (float)$matches[1] * (1024 ** $power);

		return $contentLength > $limit;
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/detach')]
	public function detachSongFile(int $id, int $fileId, ?int $libraryId = null): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songFileService->detachSongFile($user, $libraryId, $id, $fileId);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
