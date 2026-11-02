<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Service\SetlistService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class SetlistController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SetlistService $setlistService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/setlists')]
	public function getSetlists(int $libraryId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$setlists = $this->setlistService->getSetlists($user, $libraryId);
		if ($setlists === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($setlists);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/setlists/{id}')]
	public function getSetlist(int $libraryId, int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$setlist = $this->setlistService->getSetlist($user, $libraryId, $id);
		if ($setlist === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($setlist);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists')]
	public function createSetlist(?int $libraryId = null, ?string $name = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryId = $libraryId ?? (int)$this->request->getParam('libraryId', 0);
		$name = $name ?? (string)$this->request->getParam('name', '');

		if ($libraryId <= 0 || trim($name) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$created = $this->setlistService->createSetlist($user, $libraryId, $name);
		if ($created === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([
			'id' => $created->id,
			'uuid' => $created->uuid,
			'name' => $created->name,
			'sortBy' => $created->sortBy,
			'ascending' => $created->ascending,
		], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists/{id}')]
	public function updateSetlist(int $id, ?int $libraryId = null, ?string $name = null, ?int $sortBy = null, ?int $ascending = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryId = $libraryId ?? (int)$this->request->getParam('libraryId', 0);
		$name = $name ?? (string)$this->request->getParam('name', '');
		if ($sortBy === null && $this->request->getParam('sortBy') !== null) {
			$sortBy = (int)$this->request->getParam('sortBy');
		}
		if ($ascending === null && $this->request->getParam('ascending') !== null) {
			$ascending = (int)$this->request->getParam('ascending');
		}

		if ($libraryId <= 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$updated = $this->setlistService->updateSetlist(
			$user,
			$libraryId,
			$id,
			$name !== '' ? $name : null,
			$sortBy,
			$ascending,
		);

		if ($updated === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([
			'id' => $updated->id,
			'uuid' => $updated->uuid,
			'name' => $updated->name,
			'sortBy' => $updated->sortBy,
			'ascending' => $updated->ascending,
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/setlists/{id}')]
	public function deleteSetlist(int $id, ?int $libraryId = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryId = $libraryId ?? (int)$this->request->getParam('libraryId', 0);
		if ($libraryId <= 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$success = $this->setlistService->deleteSetlist($user, $libraryId, $id);
		if (!$success) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists/{id}/items')]
	public function addItem(int $id, ?int $libraryId = null, ?int $songId = null, ?int $bookmarkId = null, ?int $position = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryId = $libraryId ?? (int)$this->request->getParam('libraryId', 0);
		$songId = $songId ?? (int)$this->request->getParam('songId', 0);
		if ($bookmarkId === null && $this->request->getParam('bookmarkId') !== null && $this->request->getParam('bookmarkId') !== '') {
			$bookmarkId = (int)$this->request->getParam('bookmarkId');
		}
		if ($position === null && $this->request->getParam('position') !== null && $this->request->getParam('position') !== '') {
			$position = (int)$this->request->getParam('position');
		}

		if ($libraryId <= 0 || $songId <= 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$item = $this->setlistService->addItem($user, $libraryId, $id, $songId, $bookmarkId, $position);
		if ($item === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($item, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists/{id}/items/{itemId}')]
	public function updateItem(int $id, int $itemId, ?int $libraryId = null, ?int $bookmarkId = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryId = $libraryId ?? (int)$this->request->getParam('libraryId', 0);
		if ($libraryId <= 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$params = $this->request->getParams();
		$hasBookmarkParam = array_key_exists('bookmarkId', $params);
		if ($hasBookmarkParam) {
			$rawBookmark = $params['bookmarkId'];
			$bookmarkId = ($rawBookmark !== null && $rawBookmark !== '') ? (int)$rawBookmark : null;
		}

		$item = $this->setlistService->updateItem(
			$user,
			$libraryId,
			$id,
			$itemId,
			$bookmarkId,
			$hasBookmarkParam,
		);
		if ($item === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($item);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/setlists/{id}/items/{itemId}')]
	public function removeItem(int $id, int $itemId, ?int $libraryId = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryId = $libraryId ?? (int)$this->request->getParam('libraryId', 0);
		if ($libraryId <= 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$success = $this->setlistService->removeItem($user, $libraryId, $id, $itemId);
		if (!$success) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists/{id}/reorder')]
	public function reorderItems(int $id, ?int $libraryId = null, ?array $itemIds = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryId = $libraryId ?? (int)$this->request->getParam('libraryId', 0);
		$itemIds = $itemIds ?? $this->request->getParam('itemIds', []);

		if ($libraryId <= 0 || !is_array($itemIds)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$intItemIds = array_map('intval', $itemIds);
		$success = $this->setlistService->reorderItems($user, $libraryId, $id, $intItemIds);
		if (!$success) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['success' => true]);
	}
}
