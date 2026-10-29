<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Service\ImportProgressService;
use PHPUnit\Framework\TestCase;

final class ImportProgressServiceTest extends TestCase {
	private ImportProgressService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new ImportProgressService(null);
	}

	public function testProgressLifecycle(): void {
		$id = 'test-prog-123';
		self::assertNull($this->service->get($id));

		$this->service->start($id, 'Beginning import');
		$data = $this->service->get($id);
		self::assertNotNull($data);
		self::assertSame('running', $data['status']);
		self::assertSame(0, $data['percent']);
		self::assertSame('Beginning import', $data['message']);

		// Directory / Reconcile update
		$this->service->update($id, 'reconcile', 5, 10, 'Sonata.pdf', isMobileSheets: false);
		$data = $this->service->get($id);
		self::assertSame(50, $data['percent']);
		self::assertStringContainsString('Sonata.pdf', $data['message']);

		// Complete
		$this->service->complete($id, 'All done!');
		$data = $this->service->get($id);
		self::assertSame('completed', $data['status']);
		self::assertSame(100, $data['percent']);
		self::assertSame('All done!', $data['message']);

		// Fail
		$this->service->fail($id, 'Failed with error');
		$data = $this->service->get($id);
		self::assertSame('error', $data['status']);
		self::assertSame('Failed with error', $data['error']);
	}

	public function testMobileSheetsProgressCalculation(): void {
		$id = 'test-ms-prog';

		// Songs stage (0-45%)
		$this->service->update($id, 'songs', 10, 20, 'Ode to Joy', isMobileSheets: true);
		$data = $this->service->get($id);
		self::assertSame(23, $data['percent']); // 10/20 * 45 = 22.5 -> 23

		// Files stage (45-90%)
		$this->service->update($id, 'files', 10, 20, 'Ode.pdf', isMobileSheets: true);
		$data = $this->service->get($id);
		self::assertSame(68, $data['percent']); // 45 + 10/20 * 45 = 67.5 -> 68

		// Fixing integrity issues stage (0-90%)
		$this->service->update($id, 'fixing', 5, 10, 'Fixing duplicate entity', isMobileSheets: false);
		$data = $this->service->get($id);
		self::assertSame(45, $data['percent']); // 5/10 * 90 = 45
		self::assertStringContainsString('Fixing duplicate entity', $data['message']);

		// Finalizing stage (95%)
		$this->service->update($id, 'finalizing', 10, 10, 'Saving hashcodes', isMobileSheets: false);
		$data = $this->service->get($id);
		self::assertSame(95, $data['percent']);
		self::assertSame('Saving hashcodes', $data['message']);
	}
}
