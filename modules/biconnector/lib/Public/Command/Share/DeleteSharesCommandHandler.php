<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Superset\Dashboard\ShareExpireAgent;
use Bitrix\BIConnector\Superset\Dashboard\SharePullService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class DeleteSharesCommandHandler
{
	private SupersetDashboardShareRepository $repository;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get('biconnector.repository.share');
	}

	public function __invoke(DeleteSharesCommand $command): Result
	{
		$result = new Result();

		try
		{
			$shares = $this->repository->getByDashboardId($command->dashboardId);

			foreach ($shares as $share)
			{
				SharePullService::sendRevokeEvent($share->getToken());
				ShareExpireAgent::remove($share->getId());
				$this->repository->delete($share->getId());
			}

			// Callers gate further steps on this result, so success has to mean an
			// empty share storage, not just an absence of exceptions. Nothing to prove
			// when there was nothing to delete.
			if ($shares && $this->repository->getByDashboardId($command->dashboardId))
			{
				$result->addError(new Error(
					'Some public share links of dashboard are still stored',
					'shares_not_deleted',
				));
			}
		}
		catch (\Exception $e)
		{
			$result->addError(new Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}
}
