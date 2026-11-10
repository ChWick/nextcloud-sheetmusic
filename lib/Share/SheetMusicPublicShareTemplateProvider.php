<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Share;

use OCA\SheetMusic\AppInfo\Application;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\LibraryShareService;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\Share\IPublicShareTemplateProvider;
use OCP\Share\IPublicShareTemplateProviderWithPriority;
use OCP\Share\IShare;
use OCP\Util;

class SheetMusicPublicShareTemplateProvider implements IPublicShareTemplateProvider, IPublicShareTemplateProviderWithPriority {
	public function __construct(
		private LibraryMapper $libraryMapper,
		private LibraryShareService $libraryShareService,
		private IInitialState $initialState,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function getPriority(): int {
		return 0; // Higher priority than default files sharing provider (10)
	}

	#[\Override]
	public function shouldRespond(IShare $share): bool {
		$node = $share->getNode();
		if (!($node instanceof Folder)) {
			return false;
		}

		$lib = $this->libraryMapper->findByFolderId($node->getId());
		if ($lib !== null) {
			return true;
		}

		$token = $share->getToken();
		if ($token !== null && $token !== '') {
			$info = $this->libraryShareService->findLibraryByPublicToken($token);
			return $info !== null;
		}

		return false;
	}

	#[\Override]
	public function renderPage(IShare $share, string $token, string $path): TemplateResponse {
		$info = $this->libraryShareService->findLibraryByPublicToken($token);
		$library = $info['library'] ?? null;
		if ($library === null) {
			$node = $share->getNode();
			if ($node instanceof Folder) {
				$library = $this->libraryMapper->findByFolderId($node->getId());
			}
		}

		if ($library === null) {
			throw new NotFoundException('Sheet Music library not found');
		}

		$ownerName = $library->ownerDisplayName ?: $library->userId;

		$this->initialState->provideInitialState('isPublicShare', true);
		$this->initialState->provideInitialState('publicSharingToken', $token);
		$this->initialState->provideInitialState('publicLibrary', [
			'id' => (int)$library->id,
			'path' => $library->path,
			'sublibraryId' => $library->sublibraryId,
			'sublibraryName' => $library->sublibraryName,
			'isShared' => true,
			'ownerId' => $library->userId,
			'ownerDisplayName' => $ownerName,
			'canWrite' => false,
			'permission' => 'read',
			'folderId' => $library->folderId,
		]);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');

		$response = new PublicTemplateResponse(
			Application::APP_ID,
			'index',
		);
		$response->setHeaderTitle($library->path);
		$response->setParams(['pageTitle' => $library->path]);
		if ($ownerName !== '') {
			$response->setHeaderDetails($this->l10n->t('shared by %s', [$ownerName]));
		}

		return $response;
	}
}
