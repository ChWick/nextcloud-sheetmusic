<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Service\SetlistService;
use OCA\SheetMusic\Service\ShareService;
use OCA\SheetMusic\Service\SongAnnotationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class SongAnnotationController extends OCSController {
	private const HANDWRITING_MODEL_CONFIG_KEY = 'handwriting_model';
	private const FILE_MUSIC_FONT_CONFIG_PREFIX = 'file_music_font_';
	private const FILE_ANNOTATION_LAYERS_CONFIG_PREFIX = 'file_annotation_layers_';
	private const ALLOWED_MUSIC_FONTS = ['bravura', 'leland', 'petaluma'];
	private const MAX_ANNOTATION_LAYERS = 32;
	private const MAX_HANDWRITING_SAMPLES = 300;
	private const MAX_HANDWRITING_JSON_BYTES = 524288;

	public function __construct(
		string $appName,
		IRequest $request,
		private SongAnnotationService $annotationService,
		private IUserSession $userSession,
		private ?SetlistService $setlistService = null,
		private ?ShareService $shareService = null,
		private ?IConfig $config = null,
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

	private const ALLOWED_TYPES = ['drawing', 'highlight', 'text', 'shape', 'stamp', 'link'];

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{songId}/annotations')]
	public function saveAnnotation(int $songId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$params = $this->request->getParams();
		$annotation = $this->buildAnnotationFromParams($songId, $params);
		$saved = $this->annotationService->saveAnnotation($annotation);
		return new DataResponse($saved);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{songId}/annotations/batch')]
	public function batchAnnotations(int $songId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->processAnnotationBatch($songId, null, $this->request->getParams()));
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array{saved: list<InternalSongAnnotation>, deletedIds: list<int>}
	 */
	private function processAnnotationBatch(int $songId, ?int $forcedFileId, array $params): array {
		$upsert = is_array($params['upsert'] ?? null) ? $params['upsert'] : [];
		$deleteIds = is_array($params['deleteIds'] ?? null) ? $params['deleteIds'] : [];

		$saved = [];
		foreach ($upsert as $item) {
			if (!is_array($item)) {
				continue;
			}
			if ($forcedFileId !== null) {
				$item['fileId'] = $forcedFileId;
			}
			$annotation = $this->buildAnnotationFromParams($songId, $item);
			$saved[] = $this->annotationService->saveAnnotation($annotation);
		}

		$deleted = [];
		foreach ($deleteIds as $id) {
			if (!is_numeric($id)) {
				continue;
			}
			$intId = (int)$id;
			if ($forcedFileId !== null) {
				$existing = $this->annotationService->findAnnotationById($intId);
				if ($existing === null || $existing->fileId !== $forcedFileId) {
					continue;
				}
			}
			$this->annotationService->deleteAnnotation($intId);
			$deleted[] = $intId;
		}

		return [
			'saved' => $saved,
			'deletedIds' => $deleted,
		];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function buildAnnotationFromParams(int $songId, array $params): InternalSongAnnotation {
		$uuid = (string)($params['uuid'] ?? bin2hex(random_bytes(16)));
		$fileId = (int)($params['fileId'] ?? 0);
		$pageNumber = max(0, (int)($params['pageNumber'] ?? 0));
		$layerIndex = (int)($params['layerIndex'] ?? 0);
		$rawType = (string)($params['type'] ?? 'drawing');
		$type = in_array($rawType, self::ALLOWED_TYPES, true) ? $rawType : 'drawing';
		$x = (float)($params['x'] ?? 0.0);
		$y = (float)($params['y'] ?? 0.0);
		$width = (float)($params['width'] ?? 0.0);
		$height = (float)($params['height'] ?? 0.0);
		$style = is_array($params['style'] ?? null) ? $params['style'] : [];
		$data = is_array($params['data'] ?? null) ? $params['data'] : [];

		return new InternalSongAnnotation(
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
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/setlists/{setlistId}/files/{fileId}/annotations')]
	public function getSetlistFileAnnotations(int $libraryId, int $setlistId, int $fileId): DataResponse {
		if ($this->setlistService === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$user = $this->userSession->getUser();
		$publicToken = $this->request->getHeader('X-Public-Share-Token') ?? $this->request->getParam('token');

		if ($publicToken !== null && $publicToken !== '') {
			$info = $this->shareService?->findLibraryByPublicToken($publicToken);
			if ($info === null || (int)$info['library']->id !== $libraryId) {
				return new DataResponse([], Http::STATUS_FORBIDDEN);
			}
			$ocFileId = $this->setlistService->resolveSetlistFileOcIdForPublicToken($libraryId, $setlistId, $fileId);
		} elseif ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		} else {
			$ocFileId = $this->setlistService->resolveSetlistFileOcId($user, $libraryId, $setlistId, $fileId, false);
		}

		if ($ocFileId === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($this->annotationService->getAnnotationsForFile($ocFileId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{libraryId}/setlists/{setlistId}/files/{fileId}/annotations')]
	public function saveSetlistFileAnnotation(int $libraryId, int $setlistId, int $fileId): DataResponse {
		if ($this->setlistService === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$ocFileId = $this->setlistService->resolveSetlistFileOcId($user, $libraryId, $setlistId, $fileId, true);
		if ($ocFileId === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$params = $this->request->getParams();
		$params['fileId'] = $ocFileId;
		$annotation = $this->buildAnnotationFromParams(0, $params);
		$saved = $this->annotationService->saveAnnotation($annotation);
		return new DataResponse($saved);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{libraryId}/setlists/{setlistId}/files/{fileId}/annotations/batch')]
	public function batchSetlistFileAnnotations(int $libraryId, int $setlistId, int $fileId): DataResponse {
		if ($this->setlistService === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$ocFileId = $this->setlistService->resolveSetlistFileOcId($user, $libraryId, $setlistId, $fileId, true);
		if ($ocFileId === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($this->processAnnotationBatch(0, $ocFileId, $this->request->getParams()));
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/user/handwriting-model')]
	public function getHandwritingModel(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if ($this->config === null) {
			return new DataResponse(['samples' => []]);
		}

		$raw = $this->config->getUserValue($user->getUID(), $this->appName, self::HANDWRITING_MODEL_CONFIG_KEY, '');
		if ($raw === '') {
			return new DataResponse(['samples' => []]);
		}

		$decoded = json_decode($raw, true);
		$samples = is_array($decoded) ? $this->sanitizeHandwritingSamples($decoded) : [];
		return new DataResponse(['samples' => $samples]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/user/handwriting-model')]
	public function saveHandwritingModel(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if ($this->config === null) {
			return new DataResponse(['saved' => 0], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$rawSamples = $this->request->getParam('samples');
		if (!is_array($rawSamples)) {
			return new DataResponse(['error' => 'Invalid samples payload'], Http::STATUS_BAD_REQUEST);
		}

		$sanitized = $this->sanitizeHandwritingSamples($rawSamples);
		$encoded = json_encode($sanitized);
		if ($encoded === false || strlen($encoded) > self::MAX_HANDWRITING_JSON_BYTES) {
			return new DataResponse(['error' => 'Handwriting model payload too large'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setUserValue($user->getUID(), $this->appName, self::HANDWRITING_MODEL_CONFIG_KEY, $encoded);
		return new DataResponse(['saved' => count($sanitized)]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/user/handwriting-model')]
	public function deleteHandwritingModel(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$this->config?->deleteUserValue($user->getUID(), $this->appName, self::HANDWRITING_MODEL_CONFIG_KEY);
		return new DataResponse(['success' => true]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/files/{fileId}/music-font')]
	public function getFileMusicFont(int $fileId): DataResponse {
		if ($fileId <= 0 || $this->config === null) {
			return new DataResponse(['font' => 'bravura']);
		}
		$stored = $this->config->getAppValue($this->appName, self::FILE_MUSIC_FONT_CONFIG_PREFIX . $fileId, 'bravura');
		$font = in_array($stored, self::ALLOWED_MUSIC_FONTS, true) ? $stored : 'bravura';
		return new DataResponse(['font' => $font]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/files/{fileId}/music-font')]
	public function saveFileMusicFont(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if ($fileId <= 0 || $this->config === null) {
			return new DataResponse(['error' => 'Invalid file ID'], Http::STATUS_BAD_REQUEST);
		}
		$rawFont = $this->request->getParam('font');
		$font = is_string($rawFont) ? trim($rawFont) : '';
		if (!in_array($font, self::ALLOWED_MUSIC_FONTS, true)) {
			return new DataResponse(['error' => 'Invalid music font'], Http::STATUS_BAD_REQUEST);
		}
		$this->config->setAppValue($this->appName, self::FILE_MUSIC_FONT_CONFIG_PREFIX . $fileId, $font);
		return new DataResponse(['font' => $font]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/files/{fileId}/annotation-layers')]
	public function getFileAnnotationLayers(int $fileId): DataResponse {
		if ($fileId <= 0 || $this->config === null) {
			return new DataResponse(['layers' => $this->sanitizeAnnotationLayers([])]);
		}
		$raw = $this->config->getAppValue($this->appName, self::FILE_ANNOTATION_LAYERS_CONFIG_PREFIX . $fileId, '');
		if ($raw === '') {
			return new DataResponse(['layers' => $this->sanitizeAnnotationLayers([])]);
		}
		$decoded = json_decode($raw, true);
		$layers = is_array($decoded) ? $this->sanitizeAnnotationLayers($decoded) : $this->sanitizeAnnotationLayers([]);
		return new DataResponse(['layers' => $layers]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/files/{fileId}/annotation-layers')]
	public function saveFileAnnotationLayers(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if ($fileId <= 0 || $this->config === null) {
			return new DataResponse(['error' => 'Invalid file ID'], Http::STATUS_BAD_REQUEST);
		}
		$rawLayers = $this->request->getParam('layers');
		if (!is_array($rawLayers)) {
			return new DataResponse(['error' => 'Invalid layers payload'], Http::STATUS_BAD_REQUEST);
		}
		$sanitized = $this->sanitizeAnnotationLayers($rawLayers);
		$encoded = json_encode($sanitized);
		if ($encoded === false) {
			return new DataResponse(['error' => 'Failed to encode layers'], Http::STATUS_BAD_REQUEST);
		}
		$this->config->setAppValue($this->appName, self::FILE_ANNOTATION_LAYERS_CONFIG_PREFIX . $fileId, $encoded);
		return new DataResponse(['layers' => $sanitized]);
	}

	/**
	 * @param array<mixed> $rawLayers
	 * @return list<array{id: int, name: string, visible: bool, order: int}>
	 */
	private function sanitizeAnnotationLayers(array $rawLayers): array {
		$byId = [];
		foreach ($rawLayers as $item) {
			if (count($byId) >= self::MAX_ANNOTATION_LAYERS) {
				break;
			}
			if (!is_array($item) || !is_numeric($item['id'] ?? null)) {
				continue;
			}
			$id = max(0, (int)$item['id']);
			if (isset($byId[$id])) {
				continue;
			}
			$rawName = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
			$name = $rawName !== '' ? mb_substr($rawName, 0, 80) : ($id === 0 ? 'Default' : 'Layer ' . $id);
			$visible = array_key_exists('visible', $item) ? (bool)$item['visible'] : true;
			$order = is_numeric($item['order'] ?? null) ? (int)$item['order'] : count($byId);
			$byId[$id] = [
				'id' => $id,
				'name' => $name,
				'visible' => $visible,
				'order' => $order,
			];
		}

		if (!isset($byId[0])) {
			$byId[0] = [
				'id' => 0,
				'name' => 'Default',
				'visible' => true,
				'order' => -1,
			];
		}

		$list = array_values($byId);
		usort($list, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']) ?: ($a['id'] <=> $b['id']));
		foreach ($list as $idx => $layer) {
			$list[$idx]['order'] = $idx;
		}

		return $list;
	}

	/**
	 * @param array<mixed> $rawSamples
	 * @return list<array<string, mixed>>
	 */
	private function sanitizeHandwritingSamples(array $rawSamples): array {
		$sanitized = [];
		foreach ($rawSamples as $item) {
			if (count($sanitized) >= self::MAX_HANDWRITING_SAMPLES) {
				break;
			}
			if (!is_array($item)) {
				continue;
			}
			$symbol = isset($item['symbol']) && is_string($item['symbol']) ? trim($item['symbol']) : '';
			$points = $item['points'] ?? null;
			if ($symbol === '' || !is_array($points) || count($points) === 0 || count($points) > 64) {
				continue;
			}

			$cleanPoints = [];
			foreach ($points as $pt) {
				if (!is_array($pt) || !is_numeric($pt['x'] ?? null) || !is_numeric($pt['y'] ?? null)) {
					continue;
				}
				$cleanPoints[] = [
					'x' => round((float)$pt['x'], 4),
					'y' => round((float)$pt['y'], 4),
					'tx' => is_numeric($pt['tx'] ?? null) ? round((float)$pt['tx'], 3) : 0.0,
					'ty' => is_numeric($pt['ty'] ?? null) ? round((float)$pt['ty'], 3) : 0.0,
					'strokeId' => is_numeric($pt['strokeId'] ?? null) ? max(0, (int)$pt['strokeId']) : 0,
				];
			}
			if (count($cleanPoints) === 0) {
				continue;
			}

			$sanitized[] = [
				'symbol' => $symbol,
				'points' => $cleanPoints,
				'strokeCount' => is_numeric($item['strokeCount'] ?? null) ? max(1, (int)$item['strokeCount']) : 1,
				'aspectRatio' => is_numeric($item['aspectRatio'] ?? null) ? round((float)$item['aspectRatio'], 3) : 1.0,
				'weight' => is_numeric($item['weight'] ?? null) ? max(0.5, min(2.0, round((float)$item['weight'], 2))) : 1.0,
				'updatedAt' => is_numeric($item['updatedAt'] ?? null) ? (int)$item['updatedAt'] : 0,
			];
		}
		return $sanitized;
	}
}
