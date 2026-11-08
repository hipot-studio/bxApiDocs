<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Main\Result;

interface TerminalSessionFinalizerInterface
{
	/** Run the outer document transaction; registry calls inside the callback may nest. */
	public function withDocumentTransaction(string $hostDocKey, callable $mutation): Result;

	/** Finalize exact IDs inside withDocumentTransaction; never commit or publish here. */
	public function finalizeSessions(array $sessionIds): Result;

	/**
	 * Deactivates all local sessions and releases restrictions for a terminal document.
	 */
	public function finalizeDocument(string $externalHash): Result;

	/**
	 * Deletes one drained session and releases restrictions when it was the last active one.
	 */
	public function finalizeDrainSession(DocumentSession $session): Result;

	/**
	 * Deletes all drained sessions of one document and releases their restrictions atomically.
	 */
	public function finalizeDrainDocument(string $externalHash): Result;
}
