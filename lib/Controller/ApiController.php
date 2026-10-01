<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\EntityService;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCA\MobileSheetsManager\Service\LibrarySyncService;
use OCA\MobileSheetsManager\Service\SongFileService;
use OCA\MobileSheetsManager\Service\SongService;
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
 * Main API controller facade delegating to specialized domain controllers and services.
 *
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	private LibraryController $libraryController;
	private SongController $songController;
	private SongFileController $songFileController;
	private EntityController $entityController;

	public function __construct(
		string $appName,
		IRequest $request,
		LibraryMapper $libraryMapper,
		SongMapper $songMapper,
		IRootFolder $rootFolder,
		LoggerInterface $logger,
		IUserSession $userSession,
		FileManager $fileManager,
		LibrarySyncService $librarySync,
		?LibraryService $libraryService = null,
		?SongService $songService = null,
		?SongFileService $songFileService = null,
		?EntityService $entityService = null,
	) {
		parent::__construct($appName, $request);

		$libService = $libraryService ?? new LibraryService($libraryMapper, $songMapper, $rootFolder, $logger, $librarySync);
		$fileSvc = $songFileService ?? new SongFileService($libService, $songMapper, $fileManager, $rootFolder, $logger);
		$sngService = $songService ?? new SongService($libService, $songMapper, $fileSvc, $fileManager, $logger);
		$entService = $entityService ?? new EntityService($libService, $songMapper, $logger);

		$this->libraryController = new LibraryController($appName, $request, $libService, $userSession);
		$this->songController = new SongController($appName, $request, $sngService, $userSession);
		$this->songFileController = new SongFileController($appName, $request, $fileSvc, $userSession);
		$this->entityController = new EntityController($appName, $request, $entService, $userSession);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api')]
	public function index(): DataResponse {
		return new DataResponse(['message' => 'Hello world!']);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries')]
	public function getLibraries(): DataResponse {
		return $this->libraryController->getLibraries();
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries')]
	public function addLibrary(string $path): DataResponse {
		return $this->libraryController->addLibrary($path);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/libraries/{id}')]
	public function deleteLibrary(int $id): DataResponse {
		return $this->libraryController->deleteLibrary($id);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/sync-library')]
	public function syncLibrary(int $libraryId): DataResponse {
		return $this->libraryController->syncLibrary($libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/songs')]
	public function getSongs(?string $id = null): DataResponse {
		return $this->songController->getSongs($id);
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
	): DataResponse {
		return $this->songController->createSong($libraryId, $title, $composerIds, $bookIds, $collectionIds, $artistIds, $genreIds, $keyIds);
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
	): DataResponse {
		return $this->songController->updateSong($id, $libraryId, $title, $composerIds, $bookIds, $collectionIds, $artistIds, $genreIds, $keyIds);
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
	): DataResponse {
		return $this->songController->batchUpdateSongs($libraryId, $songIds, $composers, $books, $collections, $artists, $genres, $keys);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/songs/{id}')]
	public function deleteSong(int $id, ?int $libraryId = null, bool $deleteFiles = true): DataResponse {
		return $this->songController->deleteSong($id, $libraryId, $deleteFiles);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/import-metadata')]
	public function importSongMetadata(): DataResponse {
		return $this->songFileController->importSongMetadata();
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/upload')]
	public function uploadSongFile(int $id, ?int $libraryId = null): DataResponse {
		return $this->songFileController->uploadSongFile($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/attach')]
	public function attachNextcloudFile(
		int $id,
		?int $libraryId = null,
		?string $sourcePath = null,
		string $action = 'copy',
	): DataResponse {
		return $this->songFileController->attachNextcloudFile($id, $libraryId, $sourcePath, $action);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/delete')]
	public function deleteSongFile(int $id, int $fileId, ?int $libraryId = null): DataResponse {
		return $this->songFileController->deleteSongFile($id, $fileId, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/composers')]
	public function createComposer(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createComposer($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/books')]
	public function createBook(?int $libraryId = null, ?string $title = null): DataResponse {
		return $this->entityController->createBook($libraryId, $title);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/collections')]
	public function createCollection(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createCollection($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/artists')]
	public function createArtist(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createArtist($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/genres')]
	public function createGenre(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createGenre($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/keys')]
	public function createKey(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createKey($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/composers/{id}')]
	public function deleteComposer(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteComposer($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/books/{id}')]
	public function deleteBook(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteBook($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/collections/{id}')]
	public function deleteCollection(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteCollection($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/artists/{id}')]
	public function deleteArtist(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteArtist($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/genres/{id}')]
	public function deleteGenre(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteGenre($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/keys/{id}')]
	public function deleteKey(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteKey($id, $libraryId);
	}
}
