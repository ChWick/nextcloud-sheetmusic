<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Tests\Unit\Share;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\LibraryShareService;
use OCA\SheetMusic\Share\SheetMusicPublicShareTemplateProvider;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SheetMusicPublicShareTemplateProviderTest extends TestCase {
	private LibraryMapper&MockObject $libraryMapper;
	private LibraryShareService&MockObject $libraryShareService;
	private IInitialState&MockObject $initialState;
	private IL10N&MockObject $l10n;
	private SheetMusicPublicShareTemplateProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->libraryMapper = $this->createMock(LibraryMapper::class);
		$this->libraryShareService = $this->createMock(LibraryShareService::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->provider = new SheetMusicPublicShareTemplateProvider(
			$this->libraryMapper,
			$this->libraryShareService,
			$this->initialState,
			$this->l10n,
		);
	}

	public function testGetPriority(): void {
		self::assertSame(0, $this->provider->getPriority());
	}

	public function testShouldRespondReturnsFalseForFileNode(): void {
		$share = $this->createMock(IShare::class);
		$file = $this->createMock(File::class);
		$share->method('getNode')->willReturn($file);

		self::assertFalse($this->provider->shouldRespond($share));
	}

	public function testShouldRespondReturnsTrueWhenFolderMatchesLibrary(): void {
		$share = $this->createMock(IShare::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1234);
		$share->method('getNode')->willReturn($folder);

		$library = new InternalLibrary(id: 42, userId: 'user1', path: '/Scores', folderId: 1234);
		$this->libraryMapper->method('findByFolderId')->with(1234)->willReturn($library);

		self::assertTrue($this->provider->shouldRespond($share));
	}

	public function testShouldRespondReturnsTrueWhenTokenMatchesLibrary(): void {
		$share = $this->createMock(IShare::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1234);
		$share->method('getNode')->willReturn($folder);
		$share->method('getToken')->willReturn('testToken');

		$this->libraryMapper->method('findByFolderId')->with(1234)->willReturn(null);
		$library = new InternalLibrary(id: 42, userId: 'user1', path: '/Scores', folderId: 1234);
		$this->libraryShareService->method('findLibraryByPublicToken')->with('testToken')->willReturn([
			'library' => $library,
			'share' => $share,
		]);

		self::assertTrue($this->provider->shouldRespond($share));
	}

	public function testRenderPageSetsInitialStateAndReturnsResponse(): void {
		$share = $this->createMock(IShare::class);
		$folder = $this->createMock(Folder::class);
		$share->method('getNode')->willReturn($folder);

		$library = new InternalLibrary(
			id: 42,
			userId: 'user1',
			path: '/Scores',
			folderId: 1234,
			ownerDisplayName: 'User One',
		);
		$this->libraryShareService->method('findLibraryByPublicToken')->with('testToken')->willReturn([
			'library' => $library,
			'share' => $share,
		]);

		$this->initialState->expects(self::exactly(3))->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value): void {
				if ($key === 'isPublicShare') {
					self::assertTrue($value);
				} elseif ($key === 'publicSharingToken') {
					self::assertSame('testToken', $value);
				} elseif ($key === 'publicLibrary') {
					self::assertSame(42, $value['id']);
					self::assertSame('/Scores', $value['path']);
					self::assertFalse($value['canWrite']);
				}
			}
		);

		$this->l10n->method('t')->willReturn('shared by User One');

		$response = $this->provider->renderPage($share, 'testToken', '');
		self::assertInstanceOf(PublicTemplateResponse::class, $response);
		self::assertSame('/Scores', $response->getHeaderTitle());
	}

	public function testRenderPageThrowsNotFoundWhenNoLibraryFound(): void {
		$share = $this->createMock(IShare::class);
		$file = $this->createMock(File::class);
		$share->method('getNode')->willReturn($file);

		$this->libraryShareService->method('findLibraryByPublicToken')->with('badToken')->willReturn(null);

		$this->expectException(NotFoundException::class);
		$this->provider->renderPage($share, 'badToken', '');
	}
}
