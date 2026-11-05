<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Service\SongHistoryService;
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
		private ?SongHistoryService $songHistoryService = null,
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
			'composerIds' => $composerIds,
			'bookIds' => $bookIds,
			'collectionIds' => $collectionIds,
			'artistIds' => $artistIds,
			'genreIds' => $genreIds,
			'keyIds' => $keyIds,
			'customGroupIds' => $customGroupIds,
			'yearIds' => $yearIds,
			'signatureIds' => $signatureIds,
			'sourceTypeIds' => $sourceTypeIds,
		]);

		try {
			$result = $this->songService->createSong($user, $libraryId, $title, ...$payload);

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
			'composerIds' => $composerIds,
			'bookIds' => $bookIds,
			'collectionIds' => $collectionIds,
			'artistIds' => $artistIds,
			'genreIds' => $genreIds,
			'keyIds' => $keyIds,
			'customGroupIds' => $customGroupIds,
			'yearIds' => $yearIds,
			'signatureIds' => $signatureIds,
			'sourceTypeIds' => $sourceTypeIds,
		]);

		try {
			$result = $this->songService->updateSong($user, $id, $libraryId, $title, ...$payload);

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
		?array $arrangers = null,
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
				$this->resolveBatchParam('genres', $genres),
				$this->resolveBatchParam('keys', $keys),
				$this->resolveBatchParam('customGroups', $customGroups),
				$this->resolveBatchParam('years', $years),
				$this->resolveBatchParam('signatures', $signatures),
				$this->resolveBatchParam('sourceTypes', $sourceTypes),
				$this->resolveBatchParam('instruments', $instruments),
				$this->resolveBatchParam('arrangers', $arrangers),
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/songs/{id}/history')]
	public function getSongHistory(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->songHistoryService === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$timeline = $this->songHistoryService->getTimelineForSong($id);
		return new DataResponse($timeline);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/revert/{historyId}')]
	public function revertSongHistory(int $id, int $historyId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->songHistoryService === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$revertedSong = $this->songHistoryService->revertSongToHistory($id, $historyId, $user->getUID());
			$libraryData = $this->songService->getSongs($user, $revertedSong->libraryId);
			return new DataResponse($libraryData);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/deleted-songs')]
	public function getDeletedSongs(int $libraryId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->songHistoryService === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$deleted = $this->songHistoryService->getDeletedSongs($libraryId);
		return new DataResponse($deleted);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/restore')]
	public function restoreDeletedSong(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->songHistoryService === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$restoredSong = $this->songHistoryService->restoreDeletedSong($id, $user->getUID());
			$libraryData = $this->songService->getSongs($user, $restoredSong->libraryId);
			return new DataResponse($libraryData);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function resolveBatchParam(string $name, ?array $value): ?array {
		if ($value !== null) {
			return $value;
		}
		$raw = $this->request->getParam($name);
		return is_array($raw) && $raw !== [] ? $raw : null;
	}

	/**
	 * Extracts song fields from the incoming request.
	 *
	 * @param array<string, mixed> $overrides Explicit method arguments overriding request params.
	 * @return array<string, mixed>
	 */
	private function extractSongPayload(array $overrides = []): array {
		$resolveList = fn (string $key): array => !empty($overrides[$key])
			? (array)$overrides[$key]
			: (array)$this->request->getParam($key, []);

		$resolvePositiveInt = function (string $key): ?int {
			$raw = $this->request->getParam($key);
			if ($raw === null || $raw === '') {
				return null;
			}
			$val = (int)$raw;
			return $val > 0 ? $val : null;
		};

		return [
			'composerIds' => $resolveList('composerIds'),
			'bookIds' => $resolveList('bookIds'),
			'collectionIds' => $resolveList('collectionIds'),
			'artistIds' => $resolveList('artistIds'),
			'genreIds' => $resolveList('genreIds'),
			'keyIds' => $resolveList('keyIds'),
			'customGroupIds' => $resolveList('customGroupIds'),
			'yearIds' => $resolveList('yearIds'),
			'signatureIds' => $resolveList('signatureIds'),
			'sourceTypeIds' => $resolveList('sourceTypeIds'),
			'stars' => $resolvePositiveInt('stars'),
			'difficulty' => $resolvePositiveInt('difficulty'),
			'duration' => $resolvePositiveInt('duration'),
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
			'arrangerIds' => (array)$this->request->getParam('arrangerIds', (array)$this->request->getParam('arranger_ids', [])),
		];
	}
}
