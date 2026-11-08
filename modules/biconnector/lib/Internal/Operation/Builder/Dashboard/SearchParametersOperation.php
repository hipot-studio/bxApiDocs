<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

/**
 * Scope values the actor may pick for each url parameter of a dashboard it may view.
 *
 * Data: the answer of search_dashboard_parameters — `dashboardId`, `required`, `parameters`.
 */
final class SearchParametersOperation
{
	private readonly DashboardGuard $guard;

	public function __construct(?DashboardGuard $guard = null)
	{
		$this->guard = $guard ?? new DashboardGuard();
	}

	/**
	 * @param string|null $search Substring the values of each parameter are narrowed by.
	 * @param int $offset Zero-based offset of the page of values.
	 */
	public function execute(int $dashboardId, ?string $search, int $offset, Actor $actor): Result
	{
		$loadResult = $this->guard->forView($dashboardId, $actor);
		if (!$loadResult->isSuccess())
		{
			return $loadResult;
		}

		$describeResult = (new UrlParameters($actor->userId))->describe($dashboardId, $search, max(0, $offset));
		if (!$describeResult->isSuccess())
		{
			return Refusal::invalidArgument($describeResult);
		}

		$parameters = $describeResult->getData()['parameters'] ?? [];

		return (new Result())->setData([
			'dashboardId' => $dashboardId,
			'required' => !empty($parameters),
			'parameters' => $parameters,
		]);
	}
}
