<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\Flipchart\BoardSessionTerminationService;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\OnlyOffice\Service\OnlyOfficeSessionTerminationService;
use Throwable;

class SessionTerminationServiceFactory
{

	private NullSessionTerminationService $nullService;

	public function __construct(
		private readonly BaseObject $object,
	)
	{
		$this->nullService = new NullSessionTerminationService();
	}

	public function create(): SessionTerminationService
	{
		$session = $this->getObjectSession();

		if ($session === null || $session->getService() === null)
		{
			return $this->nullService;
		}

		return match ($session->getService())
		{
			DocumentService::OnlyOffice => $this->getOnlyOfficeService(),
			DocumentService::FlipChart => $this->getBoardsService(),
			default => $this->nullService,
		};
	}

	private function getObjectSession(): ?DocumentSession
	{
		$sessionList = DocumentSession::getModelList([
			'filter' => [
				'OBJECT_ID' => $this->object->getId(),
				'STATUS' => DocumentSession::STATUS_ACTIVE,
			],
			'limit' => 1,
		]);

		return $sessionList[0] ?? null;
	}

	private function getOnlyOfficeService(): SessionTerminationService
	{
		try
		{
			return new OnlyOfficeSessionTerminationService($this->object);
		}
		catch (Throwable)
		{
			return $this->nullService;
		}
	}

	private function getBoardsService(): SessionTerminationService
	{
		try
		{
			return new BoardSessionTerminationService($this->object);
		}
		catch (Throwable)
		{
			return $this->nullService;
		}
	}
}