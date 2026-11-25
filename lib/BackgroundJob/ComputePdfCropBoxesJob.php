<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\BackgroundJob;

use OCA\SheetMusic\Service\PdfCropService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Per-PDF queued background job that precomputes tight per-page crop boxes
 * and persists them on the song file record.
 */
class ComputePdfCropBoxesJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private PdfCropService $pdfCropService,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run(mixed $argument): void {
		if (!is_array($argument)) {
			return;
		}
		$userId = isset($argument['userId']) ? (string)$argument['userId'] : '';
		$fileId = isset($argument['fileId']) ? (int)$argument['fileId'] : 0;
		if ($userId === '' || $fileId <= 0) {
			return;
		}
		$this->pdfCropService->processFileForJob($userId, $fileId);
	}
}
