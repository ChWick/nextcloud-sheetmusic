<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Controller;

use OCA\SheetMusic\Service\EntityService;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SetlistService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongHistoryService;
use OCA\SheetMusic\Service\SongService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

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
	private SetlistController $setlistController;

	public function __construct(
		string $appName,
		IRequest $request,
		LibraryService $libraryService,
		SongService $songService,
		SongFileService $songFileService,
		EntityService $entityService,
		LibraryIntegrityService $libraryIntegrityService,
		IUserSession $userSession,
		?SongHistoryService $songHistoryService = null,
		?SetlistService $setlistService = null,
	) {
		parent::__construct($appName, $request);

		$this->libraryController = new LibraryController($appName, $request, $libraryService, $userSession, $libraryIntegrityService);
		$this->songController = new SongController($appName, $request, $songService, $userSession, $songHistoryService);
		$this->songFileController = new SongFileController($appName, $request, $songFileService, $userSession);
		$this->entityController = new EntityController($appName, $request, $entityService, $userSession);
		$this->setlistController = new SetlistController(
			$appName,
			$request,
			$setlistService ?? \OC::$server->get(SetlistService::class),
			$userSession,
		);
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
	public function addLibrary(
		string $path,
		bool $importExistingDb = true,
		?string $progressId = null,
		bool $importPdfMetadata = true,
	): DataResponse {
		return $this->libraryController->addLibrary($path, $importExistingDb, $progressId, $importPdfMetadata);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/progress/{progressId}')]
	public function getProgress(string $progressId): DataResponse {
		return $this->libraryController->getProgress($progressId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/libraries/{id}')]
	public function deleteLibrary(int $id): DataResponse {
		return $this->libraryController->deleteLibrary($id);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{id}/integrity')]
	public function getIntegrity(int $id, bool|string $autoMatchOrphans = true): DataResponse {
		return $this->libraryController->getIntegrity($id, $autoMatchOrphans);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/integrity/fix')]
	public function fixIntegrityIssue(
		int $id,
		string $issueType,
		?int $songId = null,
		?int $fileId = null,
		?string $entityType = null,
		?int $entityId = null,
		?string $path = null,
	): DataResponse {
		return $this->libraryController->fixIntegrityIssue($id, $issueType, $songId, $fileId, $entityType, $entityId, $path);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/integrity/fix-all')]
	public function fixAllIntegrityIssues(int $id, ?string $issueType = null, ?string $progressId = null): DataResponse {
		return $this->libraryController->fixAllIntegrityIssues($id, $issueType, $progressId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{id}/tools/page-order-audit')]
	public function auditPageOrders(int $id): DataResponse {
		return $this->libraryController->auditPageOrders($id);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{id}/tools/reset-page-orders')]
	public function resetPageOrders(
		int $id,
		?array $fileIds = null,
		string $mode = 'stuck',
		?string $progressId = null,
	): DataResponse {
		return $this->libraryController->resetPageOrders($id, $fileIds, $mode, $progressId);
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
		array $customGroupIds = [],
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
	): DataResponse {
		return $this->songController->createSong($libraryId, $title, $composerIds, $bookIds, $collectionIds, $artistIds, $genreIds, $keyIds, $customGroupIds, $yearIds, $signatureIds, $sourceTypeIds);
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
		return $this->songController->updateSong($id, $libraryId, $title, $composerIds, $bookIds, $collectionIds, $artistIds, $genreIds, $keyIds, $customGroupIds, $yearIds, $signatureIds, $sourceTypeIds);
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
		return $this->songController->batchUpdateSongs($libraryId, $songIds, $composers, $books, $collections, $artists, $genres, $keys, $customGroups, $years, $signatures, $sourceTypes, $instruments, $arrangers);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs-batch-delete')]
	public function batchDeleteSongs(
		?int $libraryId = null,
		array $songIds = [],
		bool $deleteFiles = true,
	): DataResponse {
		return $this->songController->batchDeleteSongs($libraryId, $songIds, $deleteFiles);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/merge')]
	public function mergeSong(
		?int $libraryId = null,
		?int $keepId = null,
		?int $droppedId = null,
		array $merged = [],
	): DataResponse {
		return $this->songController->mergeSong($libraryId, $keepId, $droppedId, $merged);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/resolve-both')]
	public function resolveBothSongs(
		?int $libraryId = null,
		?array $songA = null,
		?array $songB = null,
	): DataResponse {
		return $this->songController->resolveBothSongs($libraryId, $songA, $songB);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/songs/{id}')]
	public function deleteSong(int $id, ?int $libraryId = null, bool $deleteFiles = true): DataResponse {
		return $this->songController->deleteSong($id, $libraryId, $deleteFiles);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/delete')]
	public function deleteSongPost(int $id, ?int $libraryId = null, bool $deleteFiles = true): DataResponse {
		return $this->songController->deleteSong($id, $libraryId, $deleteFiles);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/import-metadata')]
	public function importSongMetadata(): DataResponse {
		return $this->songFileController->importSongMetadata();
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{libraryId}/songs/{id}/file/upload')]
	public function uploadSongFile(int $id, int $libraryId): DataResponse {
		return $this->songFileController->uploadSongFile($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/update')]
	public function updateSongFile(int $id, int $fileId, int $libraryId): DataResponse {
		return $this->songFileController->updateSongFile($id, $fileId, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions')]
	public function getSongFileVersions(int $id, int $fileId, int $libraryId): DataResponse {
		return $this->songFileController->getSongFileVersions($id, $fileId, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions/{revision}/revert')]
	public function revertSongFileVersion(int $id, int $fileId, int $libraryId, string $revision): DataResponse {
		return $this->songFileController->revertSongFileVersion($id, $fileId, $libraryId, $revision);
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
	#[ApiRoute(verb: 'GET', url: '/api/songs/{id}/history')]
	public function getSongHistory(int $id): DataResponse {
		return $this->songController->getSongHistory($id);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/revert/{historyId}')]
	public function revertSongHistory(int $id, int $historyId): DataResponse {
		return $this->songController->revertSongHistory($id, $historyId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/deleted-songs')]
	public function getDeletedSongs(int $libraryId): DataResponse {
		return $this->songController->getDeletedSongs($libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/restore')]
	public function restoreDeletedSong(int $id): DataResponse {
		return $this->songController->restoreDeletedSong($id);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/detach')]
	public function detachSongFile(int $id, int $fileId, ?int $libraryId = null): DataResponse {
		return $this->songFileController->detachSongFile($id, $fileId, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/page-order')]
	public function updateSongFilePageOrder(int $id, int $fileId, ?int $libraryId = null, ?string $pageOrder = null): DataResponse {
		return $this->songFileController->updateSongFilePageOrder($id, $fileId, $libraryId, $pageOrder);
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
	#[ApiRoute(verb: 'POST', url: '/api/customgroups')]
	public function createCustomGroup(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createCustomGroup($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/years')]
	public function createYear(?int $libraryId = null, ?string $name = null, ?string $year = null): DataResponse {
		return $this->entityController->createYear($libraryId, $name, $year);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/signatures')]
	public function createSignature(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createSignature($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/sourcetypes')]
	public function createSourceType(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createSourceType($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/instruments')]
	public function createInstrument(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createInstrument($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/arrangers')]
	public function createArranger(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->entityController->createArranger($libraryId, $name);
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/customgroups/{id}')]
	public function deleteCustomGroup(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteCustomGroup($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/years/{id}')]
	public function deleteYear(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteYear($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/signatures/{id}')]
	public function deleteSignature(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteSignature($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/sourcetypes/{id}')]
	public function deleteSourceType(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteSourceType($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/instruments/{id}')]
	public function deleteInstrument(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteInstrument($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/arrangers/{id}')]
	public function deleteArranger(int $id, ?int $libraryId = null): DataResponse {
		return $this->entityController->deleteArranger($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/entities/merge')]
	public function mergeEntities(
		?int $libraryId = null,
		?string $entityType = null,
		?int $keepId = null,
		mixed $duplicateIds = null,
	): DataResponse {
		return $this->entityController->mergeEntities($libraryId, $entityType, $keepId, $duplicateIds);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/setlists')]
	public function getSetlists(int $libraryId): DataResponse {
		return $this->setlistController->getSetlists($libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries/{libraryId}/setlists/{id}')]
	public function getSetlist(int $libraryId, int $id): DataResponse {
		return $this->setlistController->getSetlist($libraryId, $id);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists')]
	public function createSetlist(?int $libraryId = null, ?string $name = null): DataResponse {
		return $this->setlistController->createSetlist($libraryId, $name);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists/{id}')]
	public function updateSetlist(int $id, ?int $libraryId = null, ?string $name = null, ?int $sortBy = null, ?int $ascending = null): DataResponse {
		return $this->setlistController->updateSetlist($id, $libraryId, $name, $sortBy, $ascending);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/setlists/{id}')]
	public function deleteSetlist(int $id, ?int $libraryId = null): DataResponse {
		return $this->setlistController->deleteSetlist($id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists/{id}/items')]
	public function addSetlistItem(int $id, ?int $libraryId = null, ?int $songId = null, ?int $bookmarkId = null, ?int $position = null): DataResponse {
		return $this->setlistController->addItem($id, $libraryId, $songId, $bookmarkId, $position);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists/{id}/items/{itemId}')]
	public function updateSetlistItem(int $id, int $itemId, ?int $libraryId = null, ?int $bookmarkId = null): DataResponse {
		return $this->setlistController->updateItem($id, $itemId, $libraryId, $bookmarkId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/setlists/{id}/items/{itemId}')]
	public function removeSetlistItem(int $id, int $itemId, ?int $libraryId = null): DataResponse {
		return $this->setlistController->removeItem($id, $itemId, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/setlists/{id}/reorder')]
	public function reorderSetlistItems(int $id, ?int $libraryId = null, ?array $itemIds = null): DataResponse {
		return $this->setlistController->reorderItems($id, $libraryId, $itemIds);
	}
}
