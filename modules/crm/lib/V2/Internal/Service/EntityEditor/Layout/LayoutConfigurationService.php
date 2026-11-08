<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\V2\Internal\Access\EntityEditor\Layout\Access;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\ConfigGateway;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class LayoutConfigurationService
{
	public function __construct(
		private readonly Access $access,
		private readonly ConfigGateway $gateway,
	)
	{
	}

	public function get(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		string $scope,
	): Result
	{
		$authorizationResult = $this->authorize($actorUserId, $targetUserId, $context, $scope, 'read');
		if (!$authorizationResult->isSuccess())
		{
			return $authorizationResult;
		}

		$configurationResult = $this->gateway->get(
			$context,
			(int)$authorizationResult->getData()['configurationUserId'],
			$scope,
		);
		if (!$configurationResult->isSuccess() || ($configurationResult->getData()['layout'] ?? null) !== null)
		{
			return $configurationResult;
		}

		return $this->gateway->getDefault(
			$context,
			(int)$authorizationResult->getData()['configurationUserId'],
			$scope,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 */
	public function set(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		string $scope,
		array $layout,
	): Result
	{
		$authorizationResult = $this->authorize($actorUserId, $targetUserId, $context, $scope, 'write');
		if (!$authorizationResult->isSuccess())
		{
			return $authorizationResult;
		}

		return FailureResultFactory::normalizePartialMutationFailure($this->gateway->set(
			$context,
			(int)$authorizationResult->getData()['configurationUserId'],
			$scope,
			$layout,
		));
	}

	public function reset(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		string $scope,
	): Result
	{
		$authorizationResult = $this->authorize($actorUserId, $targetUserId, $context, $scope, 'reset');
		if (!$authorizationResult->isSuccess())
		{
			return $authorizationResult;
		}

		return $this->gateway->reset(
			$context,
			(int)$authorizationResult->getData()['configurationUserId'],
			$scope,
		);
	}

	private function authorize(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		string $scope,
		string $operation,
	): Result
	{
		$configIdResult = $this->gateway->getConfigId($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}

		if (!self::isBuiltInScope($scope))
		{
			return FailureResultFactory::unsupportedScope();
		}

		$hasAccess = match ($operation) {
			'read' => $this->access->canRead($actorUserId, $targetUserId, $context, $scope),
			'write' => $this->access->canWrite($actorUserId, $targetUserId, $context, $scope),
			'reset' => $this->access->canReset($actorUserId, $targetUserId, $context, $scope),
			default => false,
		};
		if (!$hasAccess)
		{
			return FailureResultFactory::accessDenied();
		}

		return (new Result())->setData([
			'configId' => $configIdResult->getData()['configId'],
			'configurationUserId' => $scope === EntityEditorConfigScope::PERSONAL
				? $targetUserId
				: $actorUserId,
		]);
	}

	public static function isBuiltInScope(string $scope): bool
	{
		return in_array($scope, [EntityEditorConfigScope::PERSONAL, EntityEditorConfigScope::COMMON], true);
	}
}
