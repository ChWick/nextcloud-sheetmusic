<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Service\MobileSheetsPath;
use OCA\MobileSheetsManager\Service\MobileSheetsPathResolver;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Covers MobileSheetsPathResolver, the conversion between the WebDAV paths that
 * the MobileSheets database stores and the internal paths Nextcloud works with.
 */
final class MobileSheetsPathResolverTest extends TestCase {
	private const USER_ROOT = '/admin/files';
	private const WEBDAV_ROOT = '/remote.php/dav/files/admin';

	/** Resolver whose user folder is "/admin/files" (with a trailing slash to test trimming). */
	private function resolver(string $userRoot = self::USER_ROOT . '/'): MobileSheetsPathResolver {
		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn($userRoot);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		return new MobileSheetsPathResolver($rootFolder);
	}

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		return $user;
	}

	public function testInternalRootTrimsTheTrailingSlash(): void {
		self::assertSame(self::USER_ROOT, $this->resolver()->internalRoot($this->user()));
	}

	public function testWebdavRoot(): void {
		self::assertSame(self::WEBDAV_ROOT, $this->resolver()->webdavRoot($this->user()));
	}

	public function testFromStoredBuildsBothRepresentations(): void {
		$path = $this->resolver()->fromStored(self::WEBDAV_ROOT . '/Noten/sub/file.pdf', $this->user());

		self::assertInstanceOf(MobileSheetsPath::class, $path);
		self::assertSame('/admin/files/Noten/sub/file.pdf', $path->internal);
		self::assertSame(self::WEBDAV_ROOT . '/Noten/sub/file.pdf', $path->webdav);
	}

	public function testFromStoredDecodesUrlEncodedCharactersForInternalPath(): void {
		$path = $this->resolver()->fromStored(self::WEBDAV_ROOT . '/Noten/My%20Song%20%281%29.pdf', $this->user());

		self::assertInstanceOf(MobileSheetsPath::class, $path);
		self::assertSame('/admin/files/Noten/My Song (1).pdf', $path->internal);
		self::assertSame(self::WEBDAV_ROOT . '/Noten/My%20Song%20%281%29.pdf', $path->webdav);
	}

	public function testFromStoredReturnsNullForAPathOfAnotherUser(): void {
		self::assertNull($this->resolver()->fromStored('/remote.php/dav/files/other/Noten/file.pdf', $this->user()));
	}

	/** The bare WebDAV root is no file of the user and must not be accepted. */
	public function testFromStoredReturnsNullForTheRootItself(): void {
		self::assertNull($this->resolver()->fromStored(self::WEBDAV_ROOT, $this->user()));
	}

	public function testFromStoredReturnsNullForAPrefixThatOnlyLooksLikeTheRoot(): void {
		self::assertNull($this->resolver()->fromStored('/remote.php/dav/files/admin2/file.pdf', $this->user()));
	}

	public function testFromInternalBuildsBothRepresentations(): void {
		$path = $this->resolver()->fromInternal('/admin/files/Noten/sub/file.pdf', $this->user());

		self::assertInstanceOf(MobileSheetsPath::class, $path);
		self::assertSame('/admin/files/Noten/sub/file.pdf', $path->internal);
		self::assertSame(self::WEBDAV_ROOT . '/Noten/sub/file.pdf', $path->webdav);
	}

	public function testFromInternalOfTheUserRootItself(): void {
		$path = $this->resolver()->fromInternal('/admin/files', $this->user());

		self::assertInstanceOf(MobileSheetsPath::class, $path);
		self::assertSame(self::WEBDAV_ROOT, $path->webdav);
	}

	public function testFromInternalReturnsNullOutsideTheUserFolder(): void {
		self::assertNull($this->resolver()->fromInternal('/other/files/file.pdf', $this->user()));
	}

	/** Traversal segments are collapsed before the prefix check, so they cannot escape. */
	public function testFromInternalRejectsTraversalOutOfTheUserFolder(): void {
		self::assertNull($this->resolver()->fromInternal('/admin/files/../secret.pdf', $this->user()));
	}

	public function testFromInternalCollapsesTraversalSegmentsInsideTheUserFolder(): void {
		$path = $this->resolver()->fromInternal('/admin/files/Noten/.././file.pdf', $this->user());

		self::assertInstanceOf(MobileSheetsPath::class, $path);
		self::assertSame('/admin/files/file.pdf', $path->internal);
		self::assertSame(self::WEBDAV_ROOT . '/file.pdf', $path->webdav);
	}

	public function testForTargetBuildsBothRepresentations(): void {
		$path = $this->resolver()->forTarget('Miles/Blue.pdf', $this->user(), '/admin/files/Noten');

		self::assertSame('/admin/files/Noten/Miles/Blue.pdf', $path->internal);
		self::assertSame(self::WEBDAV_ROOT . '/Noten/Miles/Blue.pdf', $path->webdav);
	}

	public function testForTargetToleratesSlashesAroundTheParts(): void {
		$path = $this->resolver()->forTarget('/Miles/Blue.pdf', $this->user(), '/admin/files/Noten/');

		self::assertSame('/admin/files/Noten/Miles/Blue.pdf', $path->internal);
	}

	/** Leaving the base folder is fine, leaving the user folder is not. */
	public function testForTargetAllowsEscapingTheBaseFolderButNotTheUserFolder(): void {
		$path = $this->resolver()->forTarget('../Other/Blue.pdf', $this->user(), '/admin/files/Noten');

		self::assertSame('/admin/files/Other/Blue.pdf', $path->internal);

		$this->expectException(\InvalidArgumentException::class);
		$this->resolver()->forTarget('../../secret.pdf', $this->user(), '/admin/files/Noten');
	}

	public function testForTargetOfTheUserRootItself(): void {
		$path = $this->resolver()->forTarget('', $this->user(), '/admin/files');

		self::assertSame('/admin/files', $path->internal);
	}

	public function testRelativeToUserFolder(): void {
		self::assertSame('Noten/sub/file.pdf', $this->resolver()->relativeToUserFolder('/admin/files/Noten/sub/file.pdf', $this->user()));
	}

	public function testRelativeToUserFolderOfTheRootItselfIsEmpty(): void {
		self::assertSame('', $this->resolver()->relativeToUserFolder('/admin/files', $this->user()));
	}

	public function testRelativeToUserFolderReturnsNullOutsideTheUserFolder(): void {
		self::assertNull($this->resolver()->relativeToUserFolder('/admin/other/file.pdf', $this->user()));
	}

	public function testRelativeToUserFolderCollapsesTraversalSegments(): void {
		self::assertSame('file.pdf', $this->resolver()->relativeToUserFolder('/admin/files/./sub/../file.pdf', $this->user()));
	}
}
