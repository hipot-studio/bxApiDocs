<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\V2\Internal\Access\EntityEditor\Layout\Access;
use Bitrix\Crm\V2\Internal\Integration\UI\EntityEditorScopeGateway;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\ConfigGateway;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class ScopeSelectionService
{
	private readonly ScopeResultValidator $scopeResultValidator;

	public function __construct(
		private readonly Access $access,
		private readonly ConfigGateway $gateway,
		private readonly EntityEditorScopeGateway $scopeGateway,
	)
	{
		$this->scopeResultValidator = new ScopeResultValidator($scopeGateway);
	}

	public function get(int $actorUserId, Context $context, ?int $subjectUserId = null): Result
	{
		$subjectUserId ??= $actorUserId;
		if (!$this->access->canActForSubject($actorUserId, $subjectUserId))
		{
			return FailureResultFactory::accessDenied();
		}

		$result = $this->gateway->getSelectedScope($context, $subjectUserId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$scope = (string)$result->getData()['scope'];
		if ($scope === EntityEditorConfigScope::CUSTOM)
		{
			$scopeId = (int)($result->getData()['scopeId'] ?? 0);
			if (!$this->access->canReadCustom($subjectUserId, $scopeId))
			{
				return $this->commonSelectionResult($result);
			}

			$scopeResult = $this->scopeGateway->readScope(
				$subjectUserId,
				$scopeId,
				(string)$result->getData()['configId'],
			);
			if (!$scopeResult->isSuccess())
			{
				return $scopeResult->getError()?->getCode() === ErrorCode::ACCESS_DENIED
					|| $scopeResult->getError()?->getCode() === ErrorCode::LAYOUT_NOT_FOUND
					|| ($scopeResult->getError()?->getCustomData()['reason'] ?? null) === 'invalid_access_code'
					? $this->commonSelectionResult($result)
					: $scopeResult;
			}

			/** @var Scope $customScope */
			$customScope = $scopeResult->getData()['scope'];
			if (!$this->scopeGateway->belongsToMode($customScope, $context))
			{
				return $this->commonSelectionResult($result);
			}
		}
		elseif (
			$scope === EntityEditorConfigScope::PERSONAL
			&& !$this->access->canSwitchToPersonalView($subjectUserId, $context)
		)
		{
			return $this->commonSelectionResult($result);
		}
		elseif (!$this->access->canRead(
			$actorUserId,
			$scope === EntityEditorConfigScope::PERSONAL ? $subjectUserId : null,
			$context,
			$scope,
		))
		{
			return FailureResultFactory::accessDenied();
		}

		return $result;
	}

	public function set(
		int $actorUserId,
		Context $context,
		string $scope,
		?int $scopeId = null,
		?int $subjectUserId = null,
	): Result
	{
		$subjectUserId ??= $actorUserId;
		if (!$this->access->canActForSubject($actorUserId, $subjectUserId))
		{
			return FailureResultFactory::accessDenied();
		}

		$configIdResult = $this->gateway->getConfigId($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}

		if ($scope === EntityEditorConfigScope::CUSTOM)
		{
			if ($scopeId === null || !$this->access->canReadCustom($subjectUserId, $scopeId))
			{
				return FailureResultFactory::layoutNotFound();
			}

			$scopeResult = $this->scopeResultValidator->ensureMatchesMode(
				$this->scopeGateway->readScope(
					$subjectUserId,
					$scopeId,
					(string)$configIdResult->getData()['configId'],
				),
				$context,
			);
			if (!$scopeResult->isSuccess())
			{
				return $scopeResult;
			}
		}
		elseif (
			$scope === EntityEditorConfigScope::PERSONAL
			&& !$this->access->canSwitchToPersonalView($subjectUserId, $context)
		)
		{
			$scope = EntityEditorConfigScope::COMMON;
		}

		if (
			$scope !== EntityEditorConfigScope::CUSTOM
			&& (
				!LayoutConfigurationService::isBuiltInScope($scope)
				|| !$this->access->canRead(
					$actorUserId,
					$scope === EntityEditorConfigScope::PERSONAL ? $subjectUserId : null,
					$context,
					$scope,
				)
			)
		)
		{
			return FailureResultFactory::accessDenied();
		}

		return FailureResultFactory::normalizePartialMutationFailure(
			$this->gateway->setSelectedScope($context, $subjectUserId, $scope, $scopeId),
		);
	}

	public function setCommonForAll(int $actorUserId, Context $context): Result
	{
		if (!$this->access->canWrite(
			$actorUserId,
			null,
			$context,
			EntityEditorConfigScope::COMMON,
		))
		{
			return FailureResultFactory::accessDenied();
		}

		return FailureResultFactory::normalizePartialMutationFailure(
			$this->gateway->clearSavedSelections($context),
		);
	}

	private function commonSelectionResult(Result $selectionResult): Result
	{
		$data = $selectionResult->getData();
		$data['scope'] = EntityEditorConfigScope::COMMON;
		$data['scopeId'] = null;

		return (new Result())->setData($data);
	}
}
