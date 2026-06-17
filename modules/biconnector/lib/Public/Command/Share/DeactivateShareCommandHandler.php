<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Superset\Dashboard\ShareExpireAgent;
use Bitrix\BIConnector\Superset\Dashboard\SharePullService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class DeactivateShareCommandHandler
{
	private SupersetDashboardShareRepository $repository;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get('biconnector.repository.share');
	}

	public function __invoke(DeactivateShareCommand $command): Result
	{
		$result = new Result();

		$share = $this->repository->getByDashboardAndUser($command->dashboardId, $command->userId);
		if (!$share)
		{
			return $result;
		}

		try
		{
			$share
				->setActive('N')
				->setDateModify(new DateTime())
			;
			$this->repository->save($share);
			ShareExpireAgent::remove($share->getId());
			SharePullService::sendRevokeEvent($share->getToken());
		}
		catch (PersistenceException|\Exception $e)
		{
			$result->addError(new Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}
}
