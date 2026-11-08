<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder\Dashboard;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\DashboardDto;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\SetDashboardContentRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftPolicy;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\SetContentCommand;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\SetContentOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Content of a dashboard, its charts and filters, a part of the dashboard resource.
 */
#[DtoType(DashboardDto::class)]
final class Content extends AbstractBuilderController
{
	use BuilderOperationSupport;

	/**
	 * biconnector.builder.dashboard.content.set: replaces the chart layout and/or the filters of a draft
	 * dashboard, the answer of set_dashboard_content. Filters kept as they are follow the new charts.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::NotCustom,
		FailureCode::PublishedReport,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function setAction(SetDashboardContentRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$this->requireResultData(self::checkSomethingToSet($request));
		$this->requireResultData(self::checkPartsAreLists($request));

		$result = ServiceLocator::getInstance()->get(SetContentOperation::class)->execute(
			new SetContentCommand($request->dashboardId, $request->charts, $request->filters),
			$actor,
			DraftPolicy::Enforce,
		);

		return new ArrayResponse($this->answer($result, $request->dashboardId));
	}

	/**
	 * Told here rather than by the operation: its text sends the agent to the MCP tools, a REST caller
	 * is sent to the REST method.
	 */
	private static function checkSomethingToSet(SetDashboardContentRequest $request): Result
	{
		$result = new Result();
		if ($request->charts === null && $request->filters === null)
		{
			$result->addError(new Error(
				'Provide at least one of: charts, filters. To rename the dashboard use '
				. 'biconnector.builder.dashboard.update.',
				FailureCode::InvalidArgument->value,
			));
		}

		return $result;
	}

	/**
	 * The MCP tool schema demands lists, the REST entry has no schema: an object would reach the builders,
	 * which index the items by number.
	 */
	private static function checkPartsAreLists(SetDashboardContentRequest $request): Result
	{
		$result = new Result();
		foreach (['charts' => $request->charts, 'filters' => $request->filters] as $part => $items)
		{
			if ($items !== null && !array_is_list($items))
			{
				$result->addError(new Error(
					$part . ' must be a list, not an object.',
					FailureCode::InvalidArgument->value,
				));
			}
		}

		return $result;
	}
}
