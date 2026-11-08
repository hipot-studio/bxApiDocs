<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Action\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart\GetChartRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart\ListChartsRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\GetChartOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\ListChartsOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * biconnector.builder.chart.list and .get: the answers of list_charts and get_chart.
 *
 * Like the tools, both check access to BI only and do not narrow charts to the dashboards the caller may
 * view (the known gap of PRD §4.2, closed by S2/S3).
 */
trait ChartReadActions
{
	use BuilderOperationSupport;

	/**
	 * Charts of the portal, up to 50 a page, with `total`, `limit`, `offset` and `has_more`.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::RateLimit,
	)]
	public function listAction(ListChartsRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(ListChartsOperation::class)->execute(
			$request->limit,
			$request->offset,
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}

	/**
	 * Configuration of a chart: name, visualization type, dataset, owners, consumer dashboards and the
	 * query part of `params`.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function getAction(GetChartRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(GetChartOperation::class)->execute($request->chartId, $actor);

		return new ArrayResponse($this->answer($result));
	}
}
