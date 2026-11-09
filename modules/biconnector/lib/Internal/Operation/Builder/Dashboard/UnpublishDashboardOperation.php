<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\DestructiveBudget;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Result;

final class UnpublishDashboardOperation
{
	private readonly DashboardGuard $guard;

	/** @param (\Closure(int): AccessController)|null $accessControllerFactory */
	public function __construct(?\Closure $accessControllerFactory = null)
	{
		$this->guard = new DashboardGuard($accessControllerFactory);
	}

	public function preview(int $dashboardId, Actor $actor): Result
	{
		$loaded = $this->guard->forWrite($dashboardId, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		$dashboard = $loaded->getData()['dashboard'];

		return (new Result())->setData([
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'wasPublished' => $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY,
		]);
	}

	public function execute(int $dashboardId, Actor $actor): Result
	{
		$loaded = $this->guard->forWrite($dashboardId, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		$spent = DestructiveBudget::forCurrentLimiter()->spend($actor, 'dashboard', $dashboardId);
		if (!$spent->isSuccess())
		{
			return $spent;
		}

		$dashboard = $loaded->getData()['dashboard'];
		$unpublishResult = (new Dashboard($dashboard))->toggleDraft(false);
		if (!$unpublishResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$unpublishResult->getErrors(),
				[
					'stage' => 'unpublish_dashboard',
					'user_id' => $actor->userId,
					'dashboard_id' => $dashboardId,
					'external_id' => (int)$dashboard->getExternalId(),
				],
			);
			$error = $unpublishResult->getError();

			return Refusal::error(FailureCode::DomainRule, $error->getMessage())
				->setData(['completed' => $error->getCustomData()['completed']])
			;
		}

		return (new Result())->setData([
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => false,
		]);
	}
}
