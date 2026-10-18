<?php

declare(strict_types=1);

namespace TestSupport;

use OCP\IUser;
use OCP\IUserSession;

final class FakeUserSession implements IUserSession {
	public function __construct(
		private ?IUser $user = null,
	) {
	}

	public function login($uid, $password) {
		return true;
	}

	public function logout() {
		$this->user = null;
	}

	public function setUser($user) {
		$this->user = $user;
	}

	public function setVolatileActiveUser(?IUser $user): void {
		$this->user = $user;
	}

	public function getUser() {
		return $this->user;
	}

	public function isLoggedIn() {
		return $this->user !== null;
	}

	public function getImpersonatingUserID(): ?string {
		return null;
	}

	public function setImpersonatingUserID(bool $useCurrentUser = true): void {
	}

	public function isIncognitoMode(): bool {
		return false;
	}

	public function setIncognitoMode(bool $mode): void {
	}
}
