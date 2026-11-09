<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Action\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\PublishedReportDeletionException;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\CopyDashboardRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\DeleteDashboardRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\CopyDashboardOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DeleteDashboardOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftPolicy;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * biconnector.builder.dashboard.delete and .copy. REST has no confirmation step: a call is the confirmed
 * deletion, so only execute() of the operation is called, and the destructive budget it spends is the one
 * delete_dashboard spends.
 *
 * The caller comes from BuilderOperationSupport::createAvailableActor(); a refused operation leaves through
 * requireResultData() with the structural part of its result (retryAfter of the budget, reason of a domain rule).
 */
trait DashboardDeleteCopyActions
{
	use BuilderOperationSupport;

	/**
	 * Deletes a custom draft dashboard; the answer of a confirmed delete_dashboard. A published one is refused:
	 * it is deleted by a person. Deleting a dashboard that is already gone answers `already_deleted`.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::NotCustom,
		FailureCode::PublishedReport,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::DomainRule,
		FailureCode::DestructiveBudget,
		FailureCode::RateLimit,
	)]
	public function deleteAction(DeleteDashboardRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(DeleteDashboardOperation::class)->execute(
			$request->dashboardId,
			$actor,
			DraftPolicy::Enforce,
		);
		if (($result->getErrors()[0] ?? null)?->getCode() === FailureCode::PublishedReport->value)
		{
			throw new PublishedReportDeletionException($result->getData());
		}

		return new ArrayResponse(
			DeleteDashboardOperation::toToolAnswer($this->requireResultData($result, $request->dashboardId)),
		);
	}

	/**
	 * Copies a dashboard as a draft the caller may edit: `id`, `name` and `published` of the copy.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function copyAction(CopyDashboardRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(CopyDashboardOperation::class)->execute(
			$request->dashboardId,
			$actor,
		);

		return new ArrayResponse($this->requireResultData($result, $request->dashboardId));
	}
}
