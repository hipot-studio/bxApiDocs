<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Disk\Document\Models\DocumentInfo;
use Bitrix\Disk\Document\Models\DocumentSession;

/**
 * What the channel of the document is told about a save, in the two forms the page knows.
 *
 * The end of the session releases the page waiting for the document, so a final save which never
 * reached Disk has to be reported all the same - with the reason, which the header turns into its text.
 * An intermediate save leaves the session going and travels under a command of its own: the listeners
 * of the end of the session must not take a Ctrl+S for the end of the editing.
 */
final class SessionReportBuilder
{
	public const COMMAND_SESSION_END = 'onlyoffice';
	public const COMMAND_INTERMEDIATE_SAVE = 'onlyofficeIntermediateSave';

	/**
	 * @return array|null the payload of the end of the session, or null when the session is not over and
	 *         there is nothing to release the page with.
	 */
	public function buildSessionEndReport(
		DocumentSession $documentSession,
		?DocumentInfo $documentInfo,
		bool $wasDocumentWritten,
		?SaveFailureCategory $failureCategory = null,
	): ?array
	{
		if (!$documentInfo || !$documentInfo->isFinished())
		{
			return null;
		}

		return $this->wrap($documentSession, 'documentSessionInfo', [
			'contentStatus' => $documentInfo->getContentStatus(),
			'wasFinallySaved' => $wasDocumentWritten && $documentInfo->wasFinallySaved(),
			'failureCategory' => $wasDocumentWritten ? null : $failureCategory?->value,
		]);
	}

	/**
	 * @return array|null the payload of an intermediate save, or null when the key has no info row to
	 *         tell the state of the content from.
	 */
	public function buildIntermediateSaveReport(
		DocumentSession $documentSession,
		?DocumentInfo $documentInfo,
		bool $wasWritten,
		?SaveFailureCategory $failureCategory = null,
	): ?array
	{
		if (!$documentInfo)
		{
			return null;
		}

		return $this->wrap($documentSession, 'saveInfo', [
			'contentStatus' => $documentInfo->getContentStatus(),
			'wasWritten' => $wasWritten,
			'failureCategory' => $wasWritten ? null : $failureCategory?->value,
		]);
	}

	private function wrap(DocumentSession $documentSession, string $infoKey, array $info): array
	{
		return [
			'object' => [
				'id' => $documentSession->getObjectId(),
			],
			'documentSession' => [
				'hash' => $documentSession->getExternalHash(),
			],
			$infoKey => $info,
		];
	}
}
