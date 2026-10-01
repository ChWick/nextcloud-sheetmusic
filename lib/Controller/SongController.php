<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Service\SongService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class SongController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SongService $songService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/songs')]
	public function getSongs(?string $id = null): DataResponse {
		$libraryId = $id === null ? -1 : (int)$id;
		if ($libraryId < 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->songService->getSongs($user, $libraryId);
			if ($data === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($data);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs')]
	public function createSong(
		?int $libraryId = null,
		?string $title = null,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
		array $genreIds = [],
		array $keyIds = [],
		array $customGroupIds = [],
		int $stars = 0,
		int $difficulty = 0,
		int $duration = 0,
		string $keywords = '',
		string $custom = '',
		string $custom2 = '',
		string $sortTitle = '',
	): DataResponse {
		$title = $title ?? '';
		if ($libraryId === null || trim($title) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songService->createSong(
				$user,
				$libraryId,
				$title,
				$composerIds,
				$bookIds,
				$collectionIds,
				$artistIds,
				$genreIds,
				$keyIds,
				$customGroupIds,
				$stars,
				$difficulty,
				$duration,
				$keywords,
				$custom,
				$custom2,
				$sortTitle,
			);

			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}')]
	public function updateSong(
		int $id,
		?int $libraryId = null,
		?string $title = null,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
		array $genreIds = [],
		array $keyIds = [],
		array $customGroupIds = [],
		int $stars = 0,
		int $difficulty = 0,
		int $duration = 0,
		string $keywords = '',
		string $custom = '',
		string $custom2 = '',
		string $sortTitle = '',
	): DataResponse {
		$title = $title ?? '';
		if ($libraryId === null || $title === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songService->updateSong(
				$user,
				$id,
				$libraryId,
				$title,
				$composerIds,
				$bookIds,
				$collectionIds,
				$artistIds,
				$genreIds,
				$keyIds,
				$customGroupIds,
				$stars,
				$difficulty,
				$duration,
				$keywords,
				$custom,
				$custom2,
				$sortTitle,
			);

			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs-batch-update')]
	public function batchUpdateSongs(
		?int $libraryId = null,
		array $songIds = [],
		?array $composers = null,
		?array $books = null,
		?array $collections = null,
		?array $artists = null,
		?array $genres = null,
		?array $keys = null,
		?array $customGroups = null,
	): DataResponse {
		if ($libraryId === null || empty($songIds)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songService->batchUpdateSongs(
				$user,
				$libraryId,
				$songIds,
				$composers,
				$books,
				$collections,
				$artists,
				$genres,
				$keys,
				$customGroups,
			);

			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/songs/{id}')]
	public function deleteSong(int $id, ?int $libraryId = null, bool $deleteFiles = true): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songService->deleteSong($user, $id, $libraryId, $deleteFiles);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}

