<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync;

/**
 * Registry holding available sheet music sync clients.
 */
class SyncClientRegistry {
	/** @var array<string, SyncClientInterface> */
	private array $clients = [];

	/** @var array<string, SyncClientInterface> */
	private array $clientsByVirtualFile = [];

	/**
	 * @param list<SyncClientInterface> $clients
	 */
	public function __construct(array $clients = []) {
		foreach ($clients as $client) {
			$this->registerClient($client);
		}
	}

	public function registerClient(SyncClientInterface $client): void {
		$this->clients[$client->getClientType()] = $client;
		foreach ($client->getVirtualFileNames() as $filename) {
			$this->clientsByVirtualFile[$filename] = $client;
		}
	}

	public function getClient(string $clientType): ?SyncClientInterface {
		return $this->clients[$clientType] ?? null;
	}

	public function getClientForVirtualFile(string $filename): ?SyncClientInterface {
		return $this->clientsByVirtualFile[$filename] ?? null;
	}

	/**
	 * @return list<string>
	 */
	public function getAllVirtualFileNames(): array {
		return array_keys($this->clientsByVirtualFile);
	}

	/**
	 * @return list<SyncClientInterface>
	 */
	public function getClients(): array {
		return array_values($this->clients);
	}
}

