<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\BackgroundJob;

use OCA\SheetMusic\Service\PdfCropService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Periodic sweeper that gradually enqueues per-PDF `ComputePdfCropBoxesJob` tasks
 * for existing PDFs that do not yet have precomputed tight crop boxes.
 */
class BackfillPdfCropBoxesJob extends TimedJob {
	private const INTERVAL_SECONDS = 900; // 15 minutes
	private const BATCH_SIZE = 2;

	public function __construct(
		ITimeFactory $time,
		private PdfCropService $pdfCropService,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run(mixed $argument): void {
		$this->pdfCropService->enqueueUncroppedPdfBatch(self::BATCH_SIZE);
	}
}
