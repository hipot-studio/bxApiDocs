<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

interface SavedDeliveryWaiterInterface
{
	public const OUTCOME_SAVED = 'saved';
	public const OUTCOME_TIMEOUT = 'timeout';

	/**
	 * Returns the processed delivery ID boundary captured before forcesave.
	 */
	public function getProcessedDeliveryIdBoundary(): int;

	/**
	 * Waits for a saved delivery newer than the captured delivery ID boundary.
	 *
	 * @return string One of the OUTCOME_* constants.
	 */
	public function wait(string $documentKey, int $processedDeliveryIdBoundary): string;
}
