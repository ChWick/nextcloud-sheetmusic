<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private LibraryMapper $libraryMapper,
		private SongMapper $songMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * An example API endpoint
	 *
	 * @return DataResponse<Http::STATUS_OK, array{message: string}, array{}>
	 *
	 * 200: Data returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api')]
	public function index(): DataResponse {
		return new DataResponse(
			['message' => 'Hello world!']
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries')]
	public function getLibraries(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse(['libraries' => $this->libraryMapper->findAllForUser($user->getUID())]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries')]
	public function addLibrary(string $path): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!str_starts_with($path, '/') || !str_ends_with(strtolower($path), '.db')) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$this->libraryMapper->addForUser($user->getUID(), $path);
		return new DataResponse(['path' => $path], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/songs')]
	public function getSongs(?string $path = null): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		if ($path === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->libraryMapper->findForUser($user->getUID(), $path)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($path, '/'));
			if ($node->getType() !== 'file') {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse(['songs' => $this->songMapper->findSongs($databasePath)]);
		} catch (\Throwable $exception) {
			$this->logger->error('Could not load songs from MobileSheets library', [
				'exception' => $exception,
				'path' => $path,
				'userId' => $user->getUID(),
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

}
