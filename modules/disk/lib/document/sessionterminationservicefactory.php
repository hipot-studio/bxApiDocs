<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\Flipchart\BoardSessionTerminationService;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\OnlyOffice\Service\OnlyOfficeSessionTerminationService;
use Bitrix\Disk\Document\Vibeoffice\Service\VibeofficeSessionTerminationService;
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

		return $this->createForService($session->getService());
	}

	/**
	 * Builds a termination service for every document engine that currently has an active
	 * session on the object. A single file may be served by more than one engine at once
	 * (e.g. a legacy OnlyOffice session left active while a new Vibeoffice session is opened
	 * after the engine is switched), so revoking rights must run for each of them — otherwise
	 * an engine whose sessions were not picked would keep its external grant.
	 *
	 * @return SessionTerminationService[]
	 */
	public function createAll(): array
	{
		$services = [];
		foreach ($this->getActiveObjectServices() as $service)
		{
			$terminationService = $this->createForService($service);
			if ($terminationService instanceof NullSessionTerminationService)
			{
				continue;
			}

			$services[] = $terminationService;
		}

		return $services;
	}

	private function createForService(DocumentService $service): SessionTerminationService
	{
		return match ($service)
		{
			DocumentService::OnlyOffice => $this->getOnlyOfficeService(),
			DocumentService::FlipChart => $this->getBoardsService(),
			DocumentService::Vibeoffice => $this->getVibeofficeService(),
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

	/**
	 * @return DocumentService[] distinct services of the object's active sessions
	 */
	private function getActiveObjectServices(): array
	{
		$rows = DocumentSessionTable::getList([
			'select' => ['SERVICE'],
			'filter' => [
				'=OBJECT_ID' => $this->object->getId(),
				'=STATUS' => DocumentSession::STATUS_ACTIVE,
			],
			'group' => ['SERVICE'],
		]);

		$services = [];
		foreach ($rows as $row)
		{
			$service = DocumentService::tryFrom((string)($row['SERVICE'] ?? ''));
			if ($service === null)
			{
				continue;
			}

			$services[$service->value] = $service;
		}

		return array_values($services);
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

	private function getVibeofficeService(): SessionTerminationService
	{
		try
		{
			return new VibeofficeSessionTerminationService($this->object);
		}
		catch (Throwable)
		{
			return $this->nullService;
		}
	}
}
