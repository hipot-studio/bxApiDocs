<?php

namespace Bitrix\BIConnector\Public\Command\DashboardChat;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardChatRepository;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Repository\Exception\PersistenceException;

class DeleteSupersetDashboardChatCommandHandler
{
	private SupersetDashboardChatRepository $repository;
	private SupersetDashboardChatResult $dashboardChatResult;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(SupersetDashboardChatRepository::class);
		$this->dashboardChatResult = new SupersetDashboardChatResult();
	}

	public function setDashboardChatResult(SupersetDashboardChatResult $dashboardChatResult): self
	{
		$this->dashboardChatResult = $dashboardChatResult;

		return $this;
	}

	public function __invoke(DeleteSupersetDashboardChatCommand $command): SupersetDashboardChatResult
	{
		try
		{
			$this->repository->delete($command->id);
		}
		catch (PersistenceException|\Exception $e)
		{
			$this->dashboardChatResult->addError(
				new Error($e->getMessage(), $e->getCode()),
			);
		}

		return $this->dashboardChatResult;
	}
}
