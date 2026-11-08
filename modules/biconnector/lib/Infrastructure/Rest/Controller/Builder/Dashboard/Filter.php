<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder\Dashboard;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\RequiresSuperset;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\DashboardDto;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\GetFilterValuesRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\AppliedFiltersShape;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\GetFilterValuesOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Filters of a dashboard, a part of the dashboard resource.
 */
#[DtoType(DashboardDto::class)]
final class Filter extends AbstractBuilderController
{
	use BuilderOperationSupport;

	/**
	 * biconnector.builder.dashboard.filter.values: distinct values of one filter, the answer of get_filter_values.
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
	public function valuesAction(GetFilterValuesRequest $request): ArrayResponse
	{
		$actor = $this->createActor();

		$this->requireResultData(AppliedFiltersShape::check($request->appliedFilters));

		$result = ServiceLocator::getInstance()->get(GetFilterValuesOperation::class)->execute(
			$request->dashboardId,
			$request->filterColumn,
			$request->search !== '' ? $request->search : null,
			$request->limit,
			$request->appliedFilters,
			$request->urlParams,
			$actor,
		);

		return new ArrayResponse($this->answer($result, $request->dashboardId));
	}
}
