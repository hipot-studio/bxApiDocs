<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\File;
use Bitrix\Main\Type\DateTime;

final class SavedContentSynchronizer
{
	private const WAIT_INTERVAL_USEC = 250_000;
	private const WAIT_TOTAL_BUDGET_USEC = 15_000_000;
	private const RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC = 60;

	private ProtocolClientInterface $protocolClient;

	public function __construct(?ProtocolClientInterface $protocolClient = null)
	{
		$this->protocolClient = $protocolClient ?? ProtocolClientFactory::create();
	}

	/**
	 * Persists an edit session before a live view session is created.
	 *
	 * The editor can be closed before the saved webhook finishes applying the new version. This
	 * method is called only for live views and also waits briefly for a just-closed edit session's
	 * saved webhook, because the platform can finish the upload after the editor window is closed.
	 */
	public function synchronize(File $file): void
	{
		$file = File::loadById($file->getId()) ?: $file;

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

		$processedHashes = [];
		foreach ($sessions as $session)
		{
			$docKey = $session->getExternalHash();
			if ($docKey === '' || isset($processedHashes[$docKey]))
			{
				continue;
			}

			$processedHashes[$docKey] = true;
			if (!$session->isActive())
			{
				if (!$this->isRecentNonActiveSession($session)
					|| $this->isFileVersionAdvanced($file, $session)
				)
				{
					continue;
				}

				// The editor has already closed, so there is no active platform session to force-save.
				// Wait for the saved webhook that belongs to this recently closed session instead.
				$this->waitForSaved($docKey, $session->getCreateTime());

				continue;
			}

			$issuedAt = new DateTime();
			$forcesaveResult = $this->protocolClient->forcesave($docKey, null);
			if ($forcesaveResult->isSuccess()
				&& ($forcesaveResult->getData()['result'] ?? null) === 'not_modified'
			)
			{
				// `not_modified` only describes the platform working copy. Its saved webhook
				// may still be queued on the host, so check Disk before treating the content
				// as current. Otherwise an immediate unified-link view can open the previous
				// version and become correct only after the webhook is eventually processed.
				$freshFile = File::loadById($file->getId()) ?: $file;
				if ($this->isFileVersionAdvanced($freshFile, $session))
				{
					continue;
				}
			}

			$this->waitForSaved($docKey, $issuedAt);
		}
	}

	private function isRecentNonActiveSession(DocumentSession $session): bool
	{
		return (new DateTime())->getTimestamp() - $session->getCreateTime()->getTimestamp()
			<= self::RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC;
	}

	private function isFileVersionAdvanced(File $file, DocumentSession $session): bool
	{
		$snapshot = $session->getContentVersionSnapshot();

		return $snapshot !== null && (int)$file->getGlobalContentVersion() > $snapshot;
	}

	private function waitForSaved(string $docKey, DateTime $issuedAt): void
	{
		$deadline = microtime(true) + (self::WAIT_TOTAL_BUDGET_USEC / 1_000_000);

		while (microtime(true) < $deadline)
		{
			if (DeliveryTable::getRow([
				'select' => ['ID'],
				'filter' => [
					'=EXTERNAL_HASH' => $docKey,
					'=EVENT' => WebhookProcessor::EVENT_SAVED,
					'>=PROCESSED_TIME' => $issuedAt,
				],
			]) !== null)
			{
				return;
			}

			usleep(self::WAIT_INTERVAL_USEC);
		}
	}
}
