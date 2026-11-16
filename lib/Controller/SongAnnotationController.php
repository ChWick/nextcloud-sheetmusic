<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Service\SongAnnotationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class SongAnnotationController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SongAnnotationService $annotationService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/songs/{songId}/annotations')]
	public function getAnnotations(int $songId, ?int $fileId = null): DataResponse {
		$user = $this->userSession->getUser();
		$publicToken = $this->request->getHeader('X-Public-Share-Token') ?? $this->request->getParam('token');

		if ($publicToken !== null && $publicToken !== '') {
			$annotations = $this->annotationService->getAnnotationsForSong($songId, $fileId);
			return new DataResponse($annotations);
		}

		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$annotations = $this->annotationService->getAnnotationsForSong($songId, $fileId);
		return new DataResponse($annotations);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{songId}/annotations')]
	public function saveAnnotation(int $songId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$params = $this->request->getParams();
		$uuid = (string)($params['uuid'] ?? bin2hex(random_bytes(16)));
		$fileId = (int)($params['fileId'] ?? 0);
		$pageNumber = (int)($params['pageNumber'] ?? 0);
		$layerIndex = (int)($params['layerIndex'] ?? 0);
		$type = (string)($params['type'] ?? 'drawing');
		$x = (float)($params['x'] ?? 0.0);
		$y = (float)($params['y'] ?? 0.0);
		$width = (float)($params['width'] ?? 0.0);
		$height = (float)($params['height'] ?? 0.0);
		$style = is_array($params['style'] ?? null) ? $params['style'] : [];
		$data = is_array($params['data'] ?? null) ? $params['data'] : [];

		$annotation = new InternalSongAnnotation(
			id: isset($params['id']) && is_numeric($params['id']) ? (int)$params['id'] : null,
			uuid: $uuid,
			songId: $songId,
			fileId: $fileId,
			pageNumber: $pageNumber,
			layerIndex: $layerIndex,
			type: $type,
			x: $x,
			y: $y,
			width: $width,
			height: $height,
			style: $style,
			data: $data,
		);

		$saved = $this->annotationService->saveAnnotation($annotation);
		return new DataResponse($saved);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/annotations/{id}')]
	public function deleteAnnotation(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$this->annotationService->deleteAnnotation($id);
		return new DataResponse(['success' => true]);
	}
}
