<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {
	private const SHARE_TTL_SECONDS = 600;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		$response = new TemplateResponse(
			Application::APP_ID,
			'index',
		);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedWorkerSrcDomain('\'self\'');
		$csp->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'POST', url: '/')]
	public function shareTarget(): RedirectResponse {
		$user = $this->userSession->getUser();
		$indexUrl = $this->urlGenerator->linkToRoute('sheetmusic.page.index');
		if ($user === null) {
			return new RedirectResponse($indexUrl);
		}

		$uploadedFiles = $this->extractUploadedFiles();
		$stagedFiles = [];
		foreach ($uploadedFiles as $entry) {
			$name = (string)($entry['name'] ?? '');
			$tmpName = (string)($entry['tmp_name'] ?? '');
			$type = (string)($entry['type'] ?? '');
			$error = (int)($entry['error'] ?? UPLOAD_ERR_NO_FILE);
			if ($error !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
				continue;
			}
			if (!$this->isSupportedShareFile($name, $type)) {
				continue;
			}
			$content = file_get_contents($tmpName);
			if ($content === false) {
				continue;
			}
			$stagedFiles[] = [
				'name' => $name,
				'type' => $type,
				'dataBase64' => base64_encode($content),
			];
		}

		if ($stagedFiles === []) {
			return new RedirectResponse($indexUrl);
		}

		$token = bin2hex(random_bytes(16));
		$dir = $this->getShareTempDir();
		$this->cleanupExpiredShares($dir);

		$payload = [
			'title' => (string)$this->request->getParam('title', ''),
			'text' => (string)$this->request->getParam('text', ''),
			'url' => (string)$this->request->getParam('url', ''),
			'files' => $stagedFiles,
		];
		file_put_contents($this->getShareFilePath($dir, $user->getUID(), $token), (string)json_encode($payload));

		return new RedirectResponse($indexUrl . '?share-target=1&share-token=' . $token);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/share-target/{token}')]
	public function getShareTargetFile(string $token): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$path = $this->getShareFilePath($this->getShareTempDir(), $user->getUID(), $token);
		if (!is_file($path)) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$raw = file_get_contents($path);
		@unlink($path);
		if ($raw === false) {
			return new JSONResponse(['error' => 'Failed to read shared payload'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return new JSONResponse(['error' => 'Invalid shared payload'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($decoded);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/sw.js')]
	public function serviceWorker(): DataDisplayResponse {
		$swPath = dirname(__DIR__, 2) . '/resources/pwa-sw.js';
		$content = is_file($swPath) ? (string)file_get_contents($swPath) : '';

		$response = new DataDisplayResponse(
			$content,
			Http::STATUS_OK,
			[
				'Content-Type' => 'application/javascript; charset=utf-8',
				'Service-Worker-Allowed' => '/',
				'Cache-Control' => 'no-cache',
			],
		);
		$csp = new EmptyContentSecurityPolicy();
		$csp->addAllowedScriptDomain('\'self\'');
		$csp->addAllowedConnectDomain('\'self\'');
		$csp->addAllowedWorkerSrcDomain('\'self\'');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/pwa-manifest')]
	public function manifest(): DataDisplayResponse {
		$indexUrl = $this->urlGenerator->linkToRoute('sheetmusic.page.index');
		$scope = rtrim($this->urlGenerator->getWebroot(), '/') . '/';
		$manifest = [
			'id' => $indexUrl,
			'name' => 'Sheet Music',
			'short_name' => 'Sheet Music',
			'description' => 'Manage and synchronize sheet music, scores, and chord charts',
			'start_url' => $indexUrl,
			'scope' => $scope,
			'display' => 'standalone',
			'theme_color' => '#0082c9',
			'background_color' => '#ffffff',
			'share_target' => [
				'action' => $indexUrl . '?share-target=1',
				'method' => 'POST',
				'enctype' => 'multipart/form-data',
				'params' => [
					'title' => 'title',
					'text' => 'text',
					'url' => 'url',
					'files' => [
						[
							'name' => 'files',
							'accept' => [
								'application/pdf',
								'.pdf',
								'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
								'.docx',
							],
						],
					],
				],
			],
			'icons' => [
				[
					'src' => $this->urlGenerator->imagePath(Application::APP_ID, 'pwa-icon-192.png'),
					'type' => 'image/png',
					'sizes' => '192x192',
					'purpose' => 'any',
				],
				[
					'src' => $this->urlGenerator->imagePath(Application::APP_ID, 'pwa-icon-512.png'),
					'type' => 'image/png',
					'sizes' => '512x512',
					'purpose' => 'any',
				],
			],
		];

		return new DataDisplayResponse(
			(string)json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
			Http::STATUS_OK,
			[
				'Content-Type' => 'application/manifest+json; charset=utf-8',
				'Cache-Control' => 'no-cache',
			],
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function extractUploadedFiles(): array {
		$raw = $this->request->getUploadedFile('files');
		if (!is_array($raw) || $raw === []) {
			return [];
		}

		if (isset($raw['name']) && !is_array($raw['name'])) {
			return [$raw];
		}

		if (!isset($raw['name']) || !is_array($raw['name'])) {
			return [];
		}

		$result = [];
		$count = count($raw['name']);
		for ($i = 0; $i < $count; $i++) {
			$result[] = [
				'name' => $raw['name'][$i] ?? '',
				'type' => $raw['type'][$i] ?? '',
				'tmp_name' => $raw['tmp_name'][$i] ?? '',
				'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
			];
		}
		return $result;
	}

	private function isSupportedShareFile(string $name, string $type): bool {
		$lower = strtolower($name);
		if (str_ends_with($lower, '.pdf') || str_ends_with($lower, '.docx')) {
			return true;
		}
		return in_array($type, [
			'application/pdf',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		], true);
	}

	private function getShareTempDir(): string {
		$dir = sys_get_temp_dir() . '/sheetmusic-pwa-shares';
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		return $dir;
	}

	private function getShareFilePath(string $dir, string $uid, string $token): string {
		return $dir . '/' . hash('sha256', $uid) . '_' . $token . '.json';
	}

	private function cleanupExpiredShares(string $dir): void {
		$files = @glob($dir . '/*.json');
		if (!is_array($files)) {
			return;
		}
		$cutoff = time() - self::SHARE_TTL_SECONDS;
		foreach ($files as $file) {
			if (@filemtime($file) < $cutoff) {
				@unlink($file);
			}
		}
	}
}
