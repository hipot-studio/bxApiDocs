<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardJsonMetadataReader;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class PublishDashboardOperation
{
	private readonly DashboardGuard $guard;

	/** @param (\Closure(int): AccessController)|null $accessControllerFactory */
	public function __construct(?\Closure $accessControllerFactory = null)
	{
		$this->guard = new DashboardGuard($accessControllerFactory);
	}

	public function preview(int $dashboardId, Actor $actor): Result
	{
		$inspected = $this->inspect($dashboardId, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		$dashboard = $inspected->getData()['dashboard'];

		return (new Result())->setData([
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
		]);
	}

	public function execute(int $dashboardId, Actor $actor, PublishPolicy $policy): Result
	{
		$inspected = $this->inspect($dashboardId, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		$dashboard = $inspected->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		return $policy === PublishPolicy::ThroughDomain
			? $this->publishThroughDomain($dashboard, $actor->userId, $dashboardId, $externalId)
			: $this->preservePrevious($dashboard, $actor->userId, $dashboardId, $externalId);
	}

	private function inspect(int $dashboardId, Actor $actor): Result
	{
		$loaded = $this->guard->forWrite($dashboardId, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		$dashboard = $loaded->getData()['dashboard'];
		$metadata = DashboardJsonMetadataReader::fetch(
			(int)$dashboard->getExternalId(),
			$actor->userId,
			$dashboardId,
			'publish_dashboard',
		);
		if (!DashboardJsonMetadataReader::hasTimeRange($metadata))
		{
			return Refusal::error(
				FailureCode::DomainRule,
				'Dashboard has no `time_range` filter configured. Every dashboard '
				. 'must have a date filter before it is published. Call '
				. 'set_dashboard_content with a `filters` array containing at '
				. 'least one `time_range` entry (use "Last quarter" as default '
				. 'if the user did not specify a period), then retry '
				. 'publish_dashboard.',
			);
		}

		return $loaded;
	}

	/**
	 * The portal user and the agent go through the same transition, so the remote answer is judged
	 * by the domain. Its reasons stay in the log: the agent gets the same refusal as before.
	 */
	private function publishThroughDomain(
		SupersetDashboard $dashboard,
		int $userId,
		int $dashboardId,
		int $externalId,
	): Result
	{
		$context = [
			'stage' => 'publish_dashboard',
			'user_id' => $userId,
			'dashboard_id' => $dashboardId,
			'external_id' => $externalId,
		];

		$publishResult = (new Dashboard($dashboard))->toggleDraft(true);
		if (!$publishResult->isSuccess())
		{
			return Refusal::unavailableBi($publishResult->getErrors(), $context);
		}

		// The domain does not check its local save, so the status is read back: the agent must not
		// report a dashboard as published while it is still a draft. A repeated call is safe.
		$savedStatus = SupersetDashboardTable::getRow([
			'select' => ['STATUS'],
			'filter' => ['=ID' => $dashboardId],
		])['STATUS'] ?? null;
		if ($savedStatus !== SupersetDashboardTable::DASHBOARD_STATUS_READY)
		{
			return Refusal::unavailableBi(
				[new Error('Dashboard was published in BI, but its local status was not saved.')],
				['stage' => 'publish_dashboard.local_save'] + $context,
			);
		}

		return (new Result())->setData([
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => true,
		]);
	}

	private function preservePrevious(
		SupersetDashboard $dashboard,
		int $userId,
		int $dashboardId,
		int $externalId,
	): Result
	{
		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, [
			'published' => true,
		]);
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
				$response->getErrors(),
				[
					'stage' => 'publish_dashboard',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		$dashboard->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_READY);
		$saveResult = $dashboard->save();
		if (!$saveResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$saveResult->getErrors(),
				[
					'stage' => 'publish_dashboard.local_save',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		return (new Result())->setData([
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => true,
		]);
	}
}
