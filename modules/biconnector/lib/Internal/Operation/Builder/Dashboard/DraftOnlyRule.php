<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * A published dashboard is not edited in place: BI Builder keeps no versions of a dashboard, so an edit
 * would reach everyone who sees it at once. The caller is sent to a draft copy instead; the text names no
 * method, since the MCP tools have no copy of their own, and REST names it in the phrase of its refusal.
 */
final class DraftOnlyRule
{
	public function check(SupersetDashboard $dashboard, DraftPolicy $policy): Result
	{
		return $this->refuseIfPublished(
			$dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY,
			$policy,
		);
	}

	/**
	 * The rule asked again right before the write, on the status stored now rather than the one loaded
	 * with the dashboard, so a publication in between is caught. It narrows the window, it does not close it.
	 *
	 * @param bool $publishedInSuperset What Superset answered about the dashboard, when the operation asked it.
	 */
	public function recheck(int $dashboardId, DraftPolicy $policy, bool $publishedInSuperset = false): Result
	{
		if ($policy === DraftPolicy::Skip)
		{
			return new Result();
		}

		$row = SupersetDashboardTable::getRow([
			'select' => ['STATUS'],
			'filter' => ['=ID' => $dashboardId],
		]);
		$publishedHere = ($row['STATUS'] ?? null) === SupersetDashboardTable::DASHBOARD_STATUS_READY;

		return $this->refuseIfPublished($publishedHere || $publishedInSuperset, $policy);
	}

	private function refuseIfPublished(bool $published, DraftPolicy $policy): Result
	{
		$result = new Result();
		if ($policy === DraftPolicy::Skip || !$published)
		{
			return $result;
		}

		return $result
			->addError(new Error(
				'Dashboard is published and cannot be edited in place: only a draft can be changed. '
				. 'Edit a draft copy of the dashboard instead.',
				FailureCode::PublishedReport->value,
			))
		;
	}
}
