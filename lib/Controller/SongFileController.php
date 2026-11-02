<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Service\SongFileService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUser;
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

		$fileInfo = $this->getUploadedFileInfo('file');

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
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{libraryId}/songs/{id}/file/upload')]
	public function uploadSongFile(int $id, int $libraryId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$fileInfo, $errorResponse] = $this->validateUploadedFile('file');
		if ($errorResponse !== null) {
			return $errorResponse;
		}

		try {
			$result = $this->songFileService->uploadSongFile($user, $libraryId, $id, $fileInfo);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\OCA\SheetMusic\Service\FileAlreadyExistsException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->songFileService->getLogger()->error('Failed to upload song file', [
				'exception' => $e,
				'songId' => $id,
				'libraryId' => $libraryId,
			]);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/update')]
	public function updateSongFile(int $id, int $fileId, int $libraryId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		[$fileInfo, $errorResponse] = $this->validateUploadedFile('file');
		if ($errorResponse !== null) {
			return $errorResponse;
		}

		try {
			$result = $this->songFileService->updateSongFile($user, $libraryId, $id, $fileId, $fileInfo);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->songFileService->getLogger()->error('Failed to update song file', [
				'exception' => $e,
				'songId' => $id,
				'fileId' => $fileId,
				'libraryId' => $libraryId,
			]);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions')]
	public function getSongFileVersions(int $id, int $fileId, int $libraryId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$versions = $this->songFileService->getSongFileVersions($user, $fileId);
			return new DataResponse($versions);
		} catch (\Throwable $e) {
			$this->songFileService->getLogger()->error('Failed to get song file versions', [
				'exception' => $e,
				'songId' => $id,
				'fileId' => $fileId,
				'libraryId' => $libraryId,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions/{revision}/revert')]
	public function revertSongFileVersion(int $id, int $fileId, int $libraryId, string $revision): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songFileService->revertSongFileVersion($user, $libraryId, $id, $fileId, $revision);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->songFileService->getLogger()->error('Failed to revert song file version', [
				'exception' => $e,
				'songId' => $id,
				'fileId' => $fileId,
				'revision' => $revision,
				'libraryId' => $libraryId,
			]);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
		return $this->handleSongFileAction(
			$libraryId,
			fn (IUser $user, int $libId): ?LibraryData => $this->songFileService->deleteSongFile($user, $libId, $id, $fileId),
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/detach')]
	public function detachSongFile(int $id, int $fileId, ?int $libraryId = null): DataResponse {
		return $this->handleSongFileAction(
			$libraryId,
			fn (IUser $user, int $libId): ?LibraryData => $this->songFileService->detachSongFile($user, $libId, $id, $fileId),
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/page-order')]
	public function updateSongFilePageOrder(int $id, int $fileId, ?int $libraryId = null, ?string $pageOrder = null): DataResponse {
		return $this->handleSongFileAction(
			$libraryId,
			fn (IUser $user, int $libId): ?LibraryData => $this->songFileService->updateSongFilePageOrder($user, $libId, $id, $fileId, $pageOrder),
		);
	}

	/**
	 * @param callable(IUser, int): ?LibraryData $action
	 */
	private function handleSongFileAction(?int $libraryId, callable $action): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $action($user, $libraryId);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Validates the uploaded file info, returning an error response on failure.
	 *
	 * @return array{?array<string, mixed>, ?DataResponse}
	 */
	private function validateUploadedFile(string $key = 'file'): array {
		$fileInfo = $this->getUploadedFileInfo($key);

		if (isset($fileInfo['error']) && (int)$fileInfo['error'] !== UPLOAD_ERR_OK) {
			[$message, $status] = $this->uploadError((int)$fileInfo['error']);
			return [null, new DataResponse(['message' => $message], $status)];
		}
		if ($fileInfo === null && $this->requestExceedsPostMaxSize()) {
			return [
				null,
				new DataResponse([
					'message' => sprintf(
						'The request exceeds PHP post_max_size (%s). Increase post_max_size on the server.',
						(string)ini_get('post_max_size'),
					),
				], Http::STATUS_REQUEST_ENTITY_TOO_LARGE),
			];
		}
		if ($fileInfo === null) {
			return [null, new DataResponse(['message' => 'No file uploaded'], Http::STATUS_BAD_REQUEST)];
		}

		return [$fileInfo, null];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function getUploadedFileInfo(string $key = 'file'): ?array {
		$fileInfo = $this->request->getUploadedFile($key);
		if ($fileInfo === null && isset($_FILES[$key])) {
			$fileInfo = $_FILES[$key];
		}
		return $fileInfo;
	}
}
