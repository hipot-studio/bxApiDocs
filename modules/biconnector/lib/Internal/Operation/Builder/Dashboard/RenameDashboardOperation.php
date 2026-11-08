<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * New title of a custom dashboard the actor may edit, set through the domain Dashboard::changeTitle()
 * the web rename uses, so Superset and the local row change together.
 */
final class RenameDashboardOperation
{
	private readonly DashboardGuard $guard;
	private readonly DraftOnlyRule $draftRule;

	public function __construct(?DashboardGuard $guard = null, ?DraftOnlyRule $draftRule = null)
	{
		$this->guard = $guard ?? new DashboardGuard();
		$this->draftRule = $draftRule ?? new DraftOnlyRule();
	}

	/**
	 * The rename as it would happen, without changing anything.
	 *
	 * Data: `oldTitle`, `newTitle`.
	 */
	public function preview(int $dashboardId, string $title, Actor $actor, DraftPolicy $policy): Result
	{
		$loaded = $this->load($dashboardId, $title, $actor, $policy);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		return (new Result())->setData([
			'oldTitle' => (string)$loaded->getData()['dashboard']->getTitle(),
			'newTitle' => trim($title),
		]);
	}

	/**
	 * Data: the answer of update_dashboard_metadata — `id`, `name`, `published`.
	 */
	public function execute(int $dashboardId, string $title, Actor $actor, DraftPolicy $policy): Result
	{
		$loaded = $this->load($dashboardId, $title, $actor, $policy);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		/** @var \Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard $dashboard */
		$dashboard = $loaded->getData()['dashboard'];

		$draft = $this->draftRule->recheck($dashboardId, $policy);
		if (!$draft->isSuccess())
		{
			return $draft;
		}

		$changed = (new Dashboard($dashboard))->changeTitle(trim($title));
		if (!$changed->isSuccess())
		{
			return Refusal::unavailableBi(
				$changed->getErrors(),
				[
					'stage' => 'update_dashboard_metadata',
					'user_id' => $actor->userId,
					'dashboard_id' => $dashboardId,
					'external_id' => (int)$dashboard->getExternalId(),
				],
			);
		}

		return (new Result())->setData([
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY,
		]);
	}

	private function load(int $dashboardId, string $title, Actor $actor, DraftPolicy $policy): Result
	{
		if (trim($title) === '')
		{
			return (new Result())->addError(new Error(
				'name is required and must be a non-empty string.',
				FailureCode::InvalidArgument->value,
			));
		}

		$loaded = $this->guard->forWrite($dashboardId, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		$draft = $this->draftRule->check($loaded->getData()['dashboard'], $policy);
		if (!$draft->isSuccess())
		{
			return $draft;
		}

		return $loaded;
	}
}
