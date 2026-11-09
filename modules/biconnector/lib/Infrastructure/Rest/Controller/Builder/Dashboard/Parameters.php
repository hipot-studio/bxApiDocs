<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder\Dashboard;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\DashboardDto;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\SearchDashboardParametersRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\SearchParametersOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Url parameters of a parameterized dashboard, a part of the dashboard resource.
 */
#[DtoType(DashboardDto::class)]
final class Parameters extends AbstractBuilderController
{
	use BuilderOperationSupport;

	/**
	 * biconnector.builder.dashboard.parameters.search: the scope values the caller may pick for each
	 * parameter, the answer of search_dashboard_parameters.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function searchAction(SearchDashboardParametersRequest $request): ArrayResponse
	{
		$actor = $this->createActor();

		$result = ServiceLocator::getInstance()->get(SearchParametersOperation::class)->execute(
			$request->dashboardId,
			$request->search !== '' ? $request->search : null,
			$request->offset,
			$actor,
		);

		return new ArrayResponse($this->answer($result, $request->dashboardId));
	}
}
