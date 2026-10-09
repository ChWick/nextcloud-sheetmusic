<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Service\EntityService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class EntityController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private EntityService $entityService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/composers')]
	public function createComposer(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		return $this->handleCreate('composer', $libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/books')]
	public function createBook(?int $libraryId = null, ?string $title = null): DataResponse {
		$title = $title ?? (string)$this->request->getParam('title', '');
		return $this->handleCreate('book', $libraryId, $title);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/collections')]
	public function createCollection(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		return $this->handleCreate('collection', $libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/artists')]
	public function createArtist(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		return $this->handleCreate('artist', $libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/genres')]
	public function createGenre(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		return $this->handleCreate('genre', $libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/keys')]
	public function createKey(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		return $this->handleCreate('key', $libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/customgroups')]
	public function createCustomGroup(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		return $this->handleCreate('customgroup', $libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/years')]
	public function createYear(?int $libraryId = null, ?string $name = null, ?string $year = null): DataResponse {
		$val = $name ?? $year ?? (string)$this->request->getParam('name', (string)$this->request->getParam('year', ''));
		return $this->handleCreate('year', $libraryId, $val);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/signatures')]
	public function createSignature(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		return $this->handleCreate('signature', $libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/sourcetypes')]
	public function createSourceType(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', (string)$this->request->getParam('type', ''));
		return $this->handleCreate('sourcetype', $libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/composers/{id}')]
	public function deleteComposer(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('composers', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/books/{id}')]
	public function deleteBook(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('books', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/collections/{id}')]
	public function deleteCollection(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('collections', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/artists/{id}')]
	public function deleteArtist(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('artists', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/genres/{id}')]
	public function deleteGenre(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('genres', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/keys/{id}')]
	public function deleteKey(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('keys', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/customgroups/{id}')]
	public function deleteCustomGroup(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('customgroups', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/years/{id}')]
	public function deleteYear(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('years', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/signatures/{id}')]
	public function deleteSignature(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('signatures', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/sourcetypes/{id}')]
	public function deleteSourceType(int $id, ?int $libraryId = null): DataResponse {
		return $this->handleDelete('sourcetypes', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/entities/merge')]
	public function mergeEntities(
		?int $libraryId = null,
		?string $entityType = null,
		?int $keepId = null,
		mixed $duplicateIds = null,
	): DataResponse {
		$libraryId = $libraryId ?? (int)$this->request->getParam('libraryId', 0);
		$entityType = $entityType ?? (string)$this->request->getParam('entityType', '');
		$keepId = $keepId ?? (int)$this->request->getParam('keepId', 0);
		$rawDuplicates = $duplicateIds ?? $this->request->getParam('duplicateIds', []);

		if (is_string($rawDuplicates)) {
			$rawDuplicates = explode(',', $rawDuplicates);
		}
		$duplicateIdsList = array_values(array_filter(
			array_map('intval', (array)$rawDuplicates),
			static fn (int $id): bool => $id > 0 && $id !== $keepId,
		));

		if ($libraryId <= 0 || trim($entityType) === '' || $keepId <= 0 || $duplicateIdsList === []) {
			return new DataResponse(['error' => 'Missing or invalid parameters.'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->entityService->mergeEntities($user, $libraryId, $entityType, $keepId, $duplicateIdsList);
			if ($result === null) {
				return new DataResponse(['error' => 'Entity not found or could not be merged.'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function handleCreate(string $type, ?int $libraryId, string $value): DataResponse {
		if ($libraryId === null || trim($value) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->entityService->createEntity($user, $libraryId, $type, $value);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @param 'composers'|'books'|'collections'|'artists'|'genres' $type
	 */
	private function handleDelete(string $type, int $id, ?int $libraryId): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->entityService->deleteEntity($user, $libraryId, $type, $id);
			if ($result === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($result);
		} catch (\Throwable $e) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}

