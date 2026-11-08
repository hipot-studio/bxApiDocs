<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Action\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\CreateDashboardRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\UpdateDashboardRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\CreateDashboardOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftPolicy;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\RenameDashboardOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * biconnector.builder.dashboard.add and .update: the answers of create_dashboard and update_dashboard_metadata.
 * A published dashboard is never edited in place here: the caller is sent to its draft copy.
 */
trait DashboardWriteActions
{
	use BuilderOperationSupport;

	/**
	 * An empty dashboard of the caller, created as a draft. A repeat with the same title answers the dashboard
	 * the caller already has, with `reused`; one an earlier call left unfinished in Superset is finished first.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function addAction(CreateDashboardRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(CreateDashboardOperation::class)->execute(
			$request->name,
			$actor,
			resumeNotInstalled: true,
		);

		return new ArrayResponse($this->answer($result));
	}

	/**
	 * New title of a draft dashboard.
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
	public function updateAction(UpdateDashboardRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(RenameDashboardOperation::class)->execute(
			$request->dashboardId,
			$request->name,
			$actor,
			DraftPolicy::Enforce,
		);

		return new ArrayResponse($this->answer($result, $request->dashboardId));
	}
}
