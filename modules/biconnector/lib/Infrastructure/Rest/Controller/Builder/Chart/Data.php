<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder\Chart;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\ChartDto;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart\GetChartDataRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\GetChartDataOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\GetChartDataQuery;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\AppliedFiltersShape;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Row-level data of charts, a part of the chart resource.
 */
#[DtoType(ChartDto::class)]
final class Data extends AbstractBuilderController
{
	use BuilderOperationSupport;

	/**
	 * biconnector.builder.chart.data.get: rows of up to five charts of a dashboard the caller may view,
	 * the answer of get_chart_data. The shape of `appliedFilters` is checked here, as the schema of the tool
	 * describes it: the operation accepts what the tool accepts. `chartIds` are checked before the dashboard
	 * is loaded.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::DomainRule,
		FailureCode::RateLimit,
	)]
	public function getAction(GetChartDataRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$this->requireResultData(AppliedFiltersShape::check($request->appliedFilters));
		$chartIds = $this->requireResultData(GetChartDataOperation::resolveChartIds($request->chartIds))['chartIds'];
		$this->requirePositiveChartIds($chartIds);

		$result = ServiceLocator::getInstance()->get(GetChartDataOperation::class)->execute(
			new GetChartDataQuery(
				$request->dashboardId,
				$chartIds,
				$request->appliedFilters,
				$request->urlParams,
				$request->rowLimit,
				$request->offset,
				$request->sortBy !== '' ? $request->sortBy : null,
				$request->sortOrder,
			),
			$actor,
		);

		return new ArrayResponse($this->answer($result, $request->dashboardId));
	}

	/**
	 * get_chart_data hands a non-positive id on to Superset and answers it as missing; REST refuses it.
	 */
	private function requirePositiveChartIds(array $chartIds): void
	{
		foreach ($chartIds as $chartId)
		{
			if ($chartId <= 0)
			{
				$this->requireResultData(
					Refusal::error(FailureCode::InvalidArgument, 'chartIds must hold positive chart ids.'),
				);
			}
		}
	}
}
