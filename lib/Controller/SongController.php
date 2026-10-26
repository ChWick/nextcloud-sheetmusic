<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Service\SongService;
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
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
	): DataResponse {
		$title = $title ?? (string)$this->request->getParam('title', '');
		if ($libraryId === null || trim($title) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$payload = $this->extractSongPayload([
			'genreIds' => $genreIds,
			'keyIds' => $keyIds,
			'customGroupIds' => $customGroupIds,
			'yearIds' => $yearIds,
			'signatureIds' => $signatureIds,
			'sourceTypeIds' => $sourceTypeIds,
		]);

		try {
			$result = $this->songService->createSong(
				$user,
				$libraryId,
				$title,
				$composerIds,
				$bookIds,
				$collectionIds,
				$artistIds,
				$payload['genreIds'],
				$payload['keyIds'],
				$payload['customGroupIds'],
				$payload['yearIds'],
				$payload['signatureIds'],
				$payload['sourceTypeIds'],
				$payload['stars'],
				$payload['difficulty'],
				$payload['duration'],
				$payload['keywords'],
				$payload['custom'],
				$payload['custom2'],
				$payload['sortTitle'],
				$payload['bookmarks'],
				$payload['audioFiles'],
				$payload['opus'],
				$payload['catalogNumber'],
				$payload['songNumber'],
				$payload['instrumentIds'],
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
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
	): DataResponse {
		$title = $title ?? (string)$this->request->getParam('title', '');
		if ($libraryId === null || trim($title) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$payload = $this->extractSongPayload([
			'genreIds' => $genreIds,
			'keyIds' => $keyIds,
			'customGroupIds' => $customGroupIds,
			'yearIds' => $yearIds,
			'signatureIds' => $signatureIds,
			'sourceTypeIds' => $sourceTypeIds,
		]);

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
				$payload['genreIds'],
				$payload['keyIds'],
				$payload['customGroupIds'],
				$payload['yearIds'],
				$payload['signatureIds'],
				$payload['sourceTypeIds'],
				$payload['stars'],
				$payload['difficulty'],
				$payload['duration'],
				$payload['keywords'],
				$payload['custom'],
				$payload['custom2'],
				$payload['sortTitle'],
				$payload['bookmarks'],
				$payload['audioFiles'],
				$payload['opus'],
				$payload['catalogNumber'],
				$payload['songNumber'],
				$payload['instrumentIds'],
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
		?array $years = null,
		?array $signatures = null,
		?array $sourceTypes = null,
		?array $instruments = null,
	): DataResponse {
		if ($libraryId === null || empty($songIds)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$instruments = $instruments ?? (array)$this->request->getParam('instruments', []);
		$instruments = empty($instruments) ? null : $instruments;

		$genres = $genres ?? $this->request->getParam('genres');
		$keys = $keys ?? $this->request->getParam('keys');
		$customGroups = $customGroups ?? $this->request->getParam('customGroups');
		$years = $years ?? $this->request->getParam('years');
		$signatures = $signatures ?? $this->request->getParam('signatures');
		$sourceTypes = $sourceTypes ?? $this->request->getParam('sourceTypes');

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
				$years,
				$signatures,
				$sourceTypes,
				$instruments,
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
	#[ApiRoute(verb: 'POST', url: '/api/songs-batch-delete')]
	public function batchDeleteSongs(
		?int $libraryId = null,
		array $songIds = [],
		bool $deleteFiles = true,
	): DataResponse {
		$libraryId = $this->resolveLibraryId($libraryId);
		$songIds = !empty($songIds) ? $songIds : (array)$this->request->getParam('songIds', []);
		$deleteFiles = $this->resolveDeleteFiles($deleteFiles);

		if ($libraryId === null || empty($songIds)) {
			return new DataResponse(['message' => 'libraryId and songIds are required'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songService->batchDeleteSongs($user, $libraryId, $songIds, $deleteFiles);
			if ($result === null) {
				return new DataResponse(['message' => 'Library not found'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/songs/{id}')]
	public function deleteSong(int $id, ?int $libraryId = null, bool $deleteFiles = true): DataResponse {
		$libraryId = $this->resolveLibraryId($libraryId);
		$deleteFiles = $this->resolveDeleteFiles($deleteFiles);

		if ($libraryId === null) {
			return new DataResponse(['message' => 'libraryId parameter is required'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songService->deleteSong($user, $id, $libraryId, $deleteFiles);
			if ($result === null) {
				return new DataResponse(['message' => 'Song or library not found'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function resolveLibraryId(?int $libraryId): ?int {
		return $libraryId ?? ((int)$this->request->getParam('libraryId', 0) ?: null);
	}

	private function resolveDeleteFiles(bool $default = true): bool {
		$param = $this->request->getParam('deleteFiles');
		return $param !== null ? filter_var($param, FILTER_VALIDATE_BOOLEAN) : $default;
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/merge')]
	public function mergeSong(
		?int $libraryId = null,
		?int $keepId = null,
		?int $droppedId = null,
		array $merged = [],
	): DataResponse {
		if ($libraryId === null || $keepId === null || $droppedId === null) {
			return new DataResponse(['message' => 'libraryId, keepId and droppedId are required'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songService->mergeSong($user, $libraryId, $keepId, $droppedId, $merged);
			if ($result === null) {
				return new DataResponse(['message' => 'Library not found'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/resolve-both')]
	public function resolveBothSongs(
		?int $libraryId = null,
		?array $songA = null,
		?array $songB = null,
	): DataResponse {
		if ($libraryId === null || $songA === null || $songB === null) {
			return new DataResponse(['message' => 'libraryId, songA, and songB are required'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->songService->resolveBothSongs($user, $libraryId, $songA, $songB);
			if (is_array($result) && isset($result['error'])) {
				return new DataResponse(['message' => $result['error']], Http::STATUS_CONFLICT);
			}
			if ($result === null) {
				return new DataResponse(['message' => 'Library not found'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Extracts song fields from the incoming request.
	 *
	 * @param array<string, mixed> $overrides Explicit method arguments overriding request params.
	 * @return array<string, mixed>
	 */
	private function extractSongPayload(array $overrides = []): array {
		return [
			'genreIds' => !empty($overrides['genreIds']) ? (array)$overrides['genreIds'] : (array)$this->request->getParam('genreIds', []),
			'keyIds' => !empty($overrides['keyIds']) ? (array)$overrides['keyIds'] : (array)$this->request->getParam('keyIds', []),
			'customGroupIds' => !empty($overrides['customGroupIds']) ? (array)$overrides['customGroupIds'] : (array)$this->request->getParam('customGroupIds', []),
			'yearIds' => !empty($overrides['yearIds']) ? (array)$overrides['yearIds'] : (array)$this->request->getParam('yearIds', []),
			'signatureIds' => !empty($overrides['signatureIds']) ? (array)$overrides['signatureIds'] : (array)$this->request->getParam('signatureIds', []),
			'sourceTypeIds' => !empty($overrides['sourceTypeIds']) ? (array)$overrides['sourceTypeIds'] : (array)$this->request->getParam('sourceTypeIds', []),
			'stars' => (int)$this->request->getParam('stars', 0),
			'difficulty' => (int)$this->request->getParam('difficulty', 0),
			'duration' => (int)$this->request->getParam('duration', 0),
			'keywords' => (string)$this->request->getParam('keywords', ''),
			'custom' => (string)$this->request->getParam('custom', ''),
			'custom2' => (string)$this->request->getParam('custom2', ''),
			'sortTitle' => (string)$this->request->getParam('sortTitle', ''),
			'bookmarks' => (array)$this->request->getParam('bookmarks', []),
			'audioFiles' => (array)$this->request->getParam('audioFiles', []),
			'opus' => (string)$this->request->getParam('opus', ''),
			'catalogNumber' => (string)$this->request->getParam('catalogNumber', (string)$this->request->getParam('catalog_number', '')),
			'songNumber' => (string)$this->request->getParam('songNumber', (string)$this->request->getParam('song_number', '')),
			'instrumentIds' => (array)$this->request->getParam('instrumentIds', (array)$this->request->getParam('instrument_ids', [])),
		];
	}
}
