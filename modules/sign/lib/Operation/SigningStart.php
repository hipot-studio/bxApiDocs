<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Main\ArgumentException;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Sign\Item\Api\Document\Signing\StartRequest;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\EventHandlerService;
use Bitrix\Sign\Service\Sign\LegalLogService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Contract;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

class SigningStart implements Contract\Operation
{
	public function __construct(
		private string $uid,
		private ?DocumentRepository $documentRepository = null,
		private ?EventHandlerService $eventHandlerService = null,
		private ?LegalLogService $legalLogService = null,
	)
	{
		$this->documentRepository ??= Container::instance()->getDocumentRepository();
		$this->eventHandlerService ??= Container::instance()->getEventHandlerService();
		$this->legalLogService ??= Container::instance()->getLegalLogService();
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();
		$document = $this->documentRepository->getByUid($this->uid);
		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$signingStartResponse = Container::instance()->getApiDocumentSigningService()
			->start(
				new StartRequest($this->uid)
			)
		;

		if (!$signingStartResponse->isSuccess())
		{
			return $result->addErrors($signingStartResponse->getErrors());
		}
		$document->status = Type\DocumentStatus::SIGNING;

		$result =  $this->documentRepository->update($document);

		if ($result->isSuccess())
		{
			$this->sendEvents($document);
			$this->legalLogService->registerDocumentStart($document);
		}

		return $result;
	}

	private function sendEvents(Document $document): void
	{
		$eventData = new EventData();
		$eventData->setEventType(EventData::TYPE_ON_STARTED)
			->setDocumentItem($document);

		try
		{
			$this->eventHandlerService->createTimelineEvent($eventData);
		}
		catch (ArgumentException|Main\ArgumentOutOfRangeException $e)
		{
		}
	}
}
