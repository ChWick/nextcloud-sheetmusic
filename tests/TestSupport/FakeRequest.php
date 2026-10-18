<?php

declare(strict_types=1);

namespace TestSupport;

use OCP\IRequest;

final class FakeRequest implements IRequest {
	public function __construct(
		private array $params = [],
		private array $uploadedFiles = [],
		private array $headers = [],
		private string $method = 'GET',
	) {
	}

	public function getHeader(string $name): string {
		return $this->headers[$name] ?? '';
	}

	public function getParam(string $key, $default = null) {
		return $this->params[$key] ?? $default;
	}

	public function getParams(): array {
		return $this->params;
	}

	public function setParam(string $key, mixed $value): void {
		$this->params[$key] = $value;
	}

	public function getMethod(): string {
		return $this->method;
	}

	public function getUploadedFile(string $key) {
		return $this->uploadedFiles[$key] ?? null;
	}

	public function setUploadedFile(string $key, ?array $file): void {
		$this->uploadedFiles[$key] = $file;
	}

	public function getEnv(string $key) {
		return null;
	}

	public function getCookie(string $key) {
		return null;
	}

	public function passesCSRFCheck(): bool {
		return true;
	}

	public function passesStrictCookieCheck(): bool {
		return true;
	}

	public function passesLaxCookieCheck(): bool {
		return true;
	}

	public function getId(): string {
		return 'fake-request';
	}

	public function getRemoteAddress(): string {
		return '127.0.0.1';
	}

	public function getServerProtocol(): string {
		return 'HTTP/1.1';
	}

	public function getHttpProtocol(): string {
		return 'http';
	}

	public function getRequestUri(): string {
		return '/';
	}

	public function getRawPathInfo(): string {
		return '';
	}

	public function getPathInfo() {
		return '';
	}

	public function getScriptName(): string {
		return 'index.php';
	}

	public function isUserAgent(array $agent): bool {
		return false;
	}

	public function getInsecureServerHost(): string {
		return 'localhost';
	}

	public function getServerHost(): string {
		return 'localhost';
	}

	public function throwDecodingExceptionIfAny(): void {
	}

	public function getFormat(): ?string {
		return 'json';
	}
}

