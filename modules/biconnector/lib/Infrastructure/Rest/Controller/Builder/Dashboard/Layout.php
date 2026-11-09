<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder\Dashboard;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\RequiresSuperset;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\DashboardLayoutDto;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\ApplyDashboardLayoutRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard\GetDashboardLayoutRequest;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftPolicy;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Layout\ApplyLayoutCommand;
use Bitrix\BIConnector\Internal\Operation\Builder\Layout\ApplyLayoutOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Layout\GetLayoutOperation;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\MethodNotFoundException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

#[DtoType(DashboardLayoutDto::class)]
final class Layout extends AbstractBuilderController
{
	use BuilderOperationSupport;

	public const FEATURE_FLAG = FlagGate::BUILDER_AGENT_SKILL;

	protected function processBeforeAction(Action $action): bool
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$this->createFlagGate()->isEnabled(self::FEATURE_FLAG, $userId))
		{
			throw new MethodNotFoundException($this->getMethodDescription()?->actionUri ?? $action->getName());
		}

		return parent::processBeforeAction($action);
	}

	#[Quota(BuilderQuotaClass::Read)]
	#[RequiresSuperset]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::InvalidArgument,
		FailureCode::DomainRule,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::RateLimit,
	)]
	public function getAction(GetDashboardLayoutRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();
		$result = ServiceLocator::getInstance()->get(GetLayoutOperation::class)->execute($request->dashboardId, $actor);

		return new ArrayResponse($this->requireResultData($this->withPublicData($result), $request->dashboardId));
	}

	#[Quota(BuilderQuotaClass::Write)]
	#[RequiresSuperset]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::NotCustom,
		FailureCode::PublishedReport,
		FailureCode::InvalidArgument,
		FailureCode::VersionConflict,
		FailureCode::DomainRule,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::RateLimit,
	)]
	public function applyAction(ApplyDashboardLayoutRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();
		$result = ServiceLocator::getInstance()->get(ApplyLayoutOperation::class)->execute(
			new ApplyLayoutCommand($request->dashboardId, $request->operations, $request->version, $request->dryRun),
			$actor,
			DraftPolicy::Enforce,
		);

		return new ArrayResponse($this->requireResultData($this->withPublicData($result), $request->dashboardId));
	}

	private function withPublicData(Result $result): Result
	{
		if ($result->isSuccess())
		{
			return $result;
		}
		$error = $result->getErrors()[0];
		$code = FailureCode::tryFrom((string)$error->getCode());
		$keys = match ($code)
		{
			FailureCode::InvalidArgument => ['index', 'operation', 'reason'],
			FailureCode::VersionConflict => ['reason', 'version'],
			FailureCode::DomainRule => ['rule', 'problems', 'limit', 'value'],
			FailureCode::Unavailable => ['reason'],
			FailureCode::RateLimit => ['retryAfter'],
			default => [],
		};

		return (new Result())->addError($error)->setData(array_intersect_key($result->getData(), array_flip($keys)));
	}
}
