<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Action\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\RequiresSuperset;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\GetDashboardRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\ListDashboardsRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\AppliedFiltersShape;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\GetDashboardOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\ListDashboardsOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\ListDashboardsQuery;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * biconnector.builder.dashboard.list and .get: the answers of list_dashboards and get_dashboard_meta.
 */
trait DashboardReadActions
{
	use BuilderOperationSupport;

	/**
	 * Dashboards the caller may view, up to 50 a page, with `has_more` and `total`. A caller with access
	 * to BI but without rights on any dashboard gets an empty page.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::RateLimit,
	)]
	public function listAction(ListDashboardsRequest $request): ArrayResponse
	{
		$actor = $this->createActor();

		$result = ServiceLocator::getInstance()->get(ListDashboardsOperation::class)->execute(
			new ListDashboardsQuery($request->query, $request->offset),
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}

	/**
	 * Skeleton of a dashboard: charts with their aggregates and the filters, without row-level data.
	 * The shape of `appliedFilters` is checked here, as the schema of the tool describes it: the operation
	 * accepts what the tool accepts.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[RequiresSuperset]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::DomainRule,
		FailureCode::RateLimit,
	)]
	public function getAction(GetDashboardRequest $request): ArrayResponse
	{
		$actor = $this->createActor();

		$this->requireResultData(AppliedFiltersShape::check($request->appliedFilters));

		$result = ServiceLocator::getInstance()->get(GetDashboardOperation::class)->execute(
			$request->dashboardId,
			$request->appliedFilters,
			$request->urlParams,
			$actor,
		);

		return new ArrayResponse($this->answer($result, $request->dashboardId));
	}
}
