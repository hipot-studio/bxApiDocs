<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Disk\Document\Models\DocumentInfo;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\OnlyOffice\Clients\CommandService\CommandServiceClientFactory;
use Bitrix\Disk\Document\OnlyOffice\Clients\CommandService\CommandServiceClientInterface;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\Messenger\KeptDocumentSaveMessage;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

/**
 * Brings to Disk the document the editor server kept after the portal refused its save.
 *
 * A refused final save and an interrupted session leave the document with the editor server, and the
 * next opening of the same key gets it back with the lost editing in it. That document reaches Disk only
 * with a save, and the editor server offers none for a document nobody changes again: a user who opens it
 * just to see that the editing is there and closes it leaves the editing with the editor server, which
 * forgets it in a day. So the portal asks for the save itself once somebody is back in the document.
 *
 * Not at the very moment of the return: the editor server reports the user connected before it has
 * brought the document back, accepts the command and saves nothing. The request is therefore deferred
 * and repeated until a save arrives, a few times at most.
 */
final class KeptDocumentRecovery
{
	public const MAX_ATTEMPTS = 3;

	private readonly \Closure $clientFactory;
	private readonly \Closure $scheduler;
	private readonly LoggerInterface $logger;

	/**
	 * @param \Closure|null $clientFactory builds the client of the command service; the one of the portal
	 *        when the caller names none. Built on use: a portal without an editor server fails only here.
	 * @param \Closure|null $scheduler `fn(int $sessionId, int $attempt)` defers an attempt; the queue of the
	 *        module when the caller names none.
	 */
	public function __construct(
		?\Closure $clientFactory = null,
		?LoggerInterface $logger = null,
		?\Closure $scheduler = null,
	)
	{
		$this->clientFactory = $clientFactory
			?? static fn(): CommandServiceClientInterface => CommandServiceClientFactory::createCommandServiceClient()
		;
		$this->scheduler = $scheduler
			?? static fn(int $sessionId, int $attempt) => (new KeptDocumentSaveMessage($sessionId, $attempt))->schedule()
		;
		$this->logger = $logger ?? ServiceLocator::getInstance()->get(DocumentSaveService::LOGGER_SERVICE_ID);
	}

	public function scheduleRecovery(DocumentSession $documentSession): void
	{
		$this->schedule((int)$documentSession->getId(), 1);
	}

	/**
	 * One deferred attempt. Anything but the editing the return left behind means the matter is settled:
	 * a save arrived, successful or not, or the session is over.
	 */
	public function continueRecovery(int $sessionId, int $attempt): void
	{
		$documentSession = DocumentSession::loadById($sessionId);
		if (!$documentSession || !$documentSession->isActive())
		{
			return;
		}

		if ($documentSession->getInfo()?->getContentStatus() !== DocumentInfo::CONTENT_STATUS_EDITING)
		{
			return;
		}

		$this->requestSave($documentSession);

		if ($attempt < self::MAX_ATTEMPTS)
		{
			$this->schedule($sessionId, $attempt + 1);
		}
	}

	private function schedule(int $sessionId, int $attempt): void
	{
		try
		{
			($this->scheduler)($sessionId, $attempt);
		}
		catch (\Throwable $exception)
		{
			$this->logger->warning('OnlyOffice kept document: could not defer the save. {message}', [
				'sessionId' => $sessionId,
				'message' => $exception::class . ': ' . $exception->getMessage(),
			]);
		}
	}

	/**
	 * The save arrives as an intermediate one through the callback of the editor server, so nothing here
	 * writes a version: a refusal of the command changes no state and is only written down.
	 */
	public function requestSave(DocumentSession $documentSession): Result
	{
		$context = [
			'sessionId' => (int)$documentSession->getId(),
			'objectId' => $documentSession->getObjectId(),
		];

		try
		{
			$result = ($this->clientFactory)()->forceSave($documentSession->getExternalHash());
		}
		catch (\Throwable $exception)
		{
			$result = (new Result())->addError(new Error($exception::class . ': ' . $exception->getMessage()));
		}

		if ($result->isSuccess())
		{
			$this->logger->info('OnlyOffice kept document: a save was requested from the editor server.', $context);
		}
		else
		{
			$context['message'] = implode('; ', $result->getErrorMessages());
			$this->logger->warning('OnlyOffice kept document: the editor server refused to save it. {message}', $context);
		}

		return $result;
	}
}
