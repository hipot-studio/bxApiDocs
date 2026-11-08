<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\File;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class SavedContentSynchronizer
{
	private const QUEUED_BUDGET_SEC = 6.0;
	private const RECENT_CLOSED_BUDGET_SEC = 3.0;
	private const POLL_INTERVAL_MS = 300;
	private const POLL_INTERVAL_GROWTH = 2;
	private const POLL_INTERVAL_MAX_FACTOR = 4;
	private const RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC = 60;

	private const CONFLICT_STATUS = 409;
	private const RESULT_NOT_MODIFIED = 'not_modified';
	/** Conflict codes that mean the platform has nothing left to save. */
	private const NOTHING_TO_SAVE_CODES = ['not_active', 'no_editor_session'];

	private ProtocolClientInterface $protocolClient;

	public function __construct(
		?ProtocolClientInterface $protocolClient = null,
		private readonly float $queuedBudgetSec = self::QUEUED_BUDGET_SEC,
		private readonly float $recentClosedBudgetSec = self::RECENT_CLOSED_BUDGET_SEC,
		private readonly int $pollIntervalMs = self::POLL_INTERVAL_MS,
	)
	{
		$this->protocolClient = $protocolClient ?? ProtocolClientFactory::create();
	}

	/**
	 * Persists an edit session before a live view session is created.
	 *
	 * The editor can be closed before the saved webhook finishes applying the new version, so this
	 * method is called only for live views. Waiting is conditional: the answer of the platform to
	 * forcesave already says whether a save is on its way, and a just-closed edit session is given a
	 * shorter budget of its own, because the platform can finish the upload after the editor window
	 * is closed.
	 */
	public function synchronize(File $file): void
	{
		$fileId = (int)$file->getId();
		$file = File::loadById($fileId) ?: $file;

		$sessions = DocumentSession::getModelList([
			'select' => ['ID', 'EXTERNAL_HASH', 'STATUS', 'CONTEXT', 'CREATE_TIME'],
			'filter' => [
				'=OBJECT_ID' => $file->getRealObjectId(),
				'=VERSION_ID' => null,
				'=TYPE' => DocumentSession::TYPE_EDIT,
				'@STATUS' => [DocumentSession::STATUS_ACTIVE, DocumentSession::STATUS_NON_ACTIVE],
				'=SERVICE' => DocumentService::Vibeoffice->value,
			],
			'order' => ['ID' => 'DESC'],
		]);

		if (!$sessions)
		{
			return;
		}

		$sessionsByKey = [];
		foreach ($sessions as $session)
		{
			$docKey = HostDocumentKey::resolve($session);
			if ($docKey === '')
			{
				continue;
			}

			if (!isset($sessionsByKey[$docKey]) || (!$sessionsByKey[$docKey]->isActive() && $session->isActive()))
			{
				$sessionsByKey[$docKey] = $session;
			}
		}

		foreach ($sessionsByKey as $docKey => $session)
		{
			$snapshot = $session->getContentVersionSnapshot();
			if (!$session->isActive())
			{
				// The editor has already closed, so there is no platform session to force-save: only
				// the saved webhook of that session can still advance the version, and only for a while.
				if ($this->isRecentNonActiveSession($session) && !$this->hasFileVersionAdvanced($fileId, $snapshot))
				{
					$this->waitForVersion($fileId, $snapshot, $this->recentClosedBudgetSec);
				}

				continue;
			}

			$currentFile = $snapshot === null ? null : File::loadById($fileId);
			$baseline = $currentFile === null ? null : (int)$currentFile->getGlobalContentVersion();
			if ($this->isSavePending($this->protocolClient->forcesave($docKey, null)))
			{
				$this->waitForVersion($fileId, $baseline, $this->queuedBudgetSec);
			}
		}
	}

	/**
	 * The decision table over the answer of the platform to forcesave: `200 not_modified`,
	 * `409 not_active` and `409 no_editor_session` all mean there is nothing left to save, so there is
	 * nothing to wait for either. `200 queued` and every unknown outcome of the platform are read as a
	 * save still on its way: the cautious path, because an unknown outcome is not a success.
	 *
	 * A failure that never reached the platform is a different thing: it carries no status of its own,
	 * and a save the platform was never asked for is not on its way.
	 */
	private function isSavePending(Result $forcesaveResult): bool
	{
		if ($forcesaveResult->isSuccess())
		{
			return ($forcesaveResult->getData()['result'] ?? null) !== self::RESULT_NOT_MODIFIED;
		}

		$platformAnswered = false;
		foreach ($forcesaveResult->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			if (!is_array($customData))
			{
				continue;
			}

			$httpStatus = (int)($customData['httpStatus'] ?? 0);
			if ($httpStatus <= 0)
			{
				continue;
			}

			$platformAnswered = true;

			if (
				$httpStatus === self::CONFLICT_STATUS
				&& in_array($customData['platformCode'] ?? null, self::NOTHING_TO_SAVE_CODES, true)
			)
			{
				return false;
			}
		}

		return $platformAnswered;
	}

	private function isRecentNonActiveSession(DocumentSession $session): bool
	{
		return (new DateTime())->getTimestamp() - $session->getCreateTime()->getTimestamp()
			<= self::RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC;
	}

	/**
	 * A session that recorded no snapshot leaves the criterion below unanswerable, so the budget would
	 * be spent on a comparison that cannot become true on any iteration.
	 *
	 * The interval between checks grows up to a cap: a save that lands quickly - the common case - is
	 * still noticed within the first interval, while a wait that runs on costs a fraction of the reads
	 * a fixed step would. The last sleep never outlives the budget.
	 */
	private function waitForVersion(int $fileId, ?int $baseline, float $budgetSec): void
	{
		if ($baseline === null)
		{
			return;
		}

		$deadline = microtime(true) + $budgetSec;
		$intervalUsec = max(1, $this->pollIntervalMs * 1000);
		$maxIntervalUsec = max(1, $this->pollIntervalMs * self::POLL_INTERVAL_MAX_FACTOR * 1000);

		while (microtime(true) < $deadline)
		{
			if ($this->hasFileVersionAdvanced($fileId, $baseline))
			{
				return;
			}

			$remainingUsec = (int)round(($deadline - microtime(true)) * 1000000);
			if ($remainingUsec <= 0)
			{
				return;
			}

			usleep(min($intervalUsec, $remainingUsec));
			$intervalUsec = min($intervalUsec * self::POLL_INTERVAL_GROWTH, $maxIntervalUsec);
		}
	}

	/** Active saves compare against the version read before forcesave; closed sessions keep their snapshot. */
	private function hasFileVersionAdvanced(int $fileId, ?int $baseline): bool
	{
		if ($baseline === null)
		{
			return false;
		}

		$file = File::loadById($fileId);

		return $file !== null && (int)$file->getGlobalContentVersion() > $baseline;
	}
}
