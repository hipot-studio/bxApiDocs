<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

use Bitrix\Crm\V2\Internal\Access\EntityEditor\Layout\Access;
use Bitrix\Crm\V2\Internal\Integration\UI\EntityEditorScopeGateway;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\ConfigGateway;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class Service
{
	private readonly CustomScopeService $customScopeService;
	private readonly LayoutConfigurationService $configurationService;
	private readonly ScopeSelectionService $scopeSelectionService;

	public function __construct(
		?Access $access = null,
		?ConfigGateway $gateway = null,
		?EntityEditorScopeGateway $scopeGateway = null,
	)
	{
		$access ??= new Access();
		$gateway ??= new ConfigGateway();
		$scopeGateway ??= new EntityEditorScopeGateway();

		$this->customScopeService = new CustomScopeService($access, $gateway, $scopeGateway);
		$this->configurationService = new LayoutConfigurationService($access, $gateway);
		$this->scopeSelectionService = new ScopeSelectionService($access, $gateway, $scopeGateway);
	}

	public function getCustomScope(
		int $actorUserId,
		Context $context,
		int $scopeId,
		?int $subjectUserId = null,
	): Result
	{
		return $this->customScopeService->get($actorUserId, $context, $scopeId, $subjectUserId);
	}

	public function listCustomScopes(
		int $actorUserId,
		Context $context,
		?int $subjectUserId = null,
		int $limit = 50,
		int $offset = 0,
	): Result
	{
		return $this->customScopeService->list($actorUserId, $context, $subjectUserId, $limit, $offset);
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 * @param array<int, array{type?: mixed, id?: mixed}|object> $accessCodes
	 */
	public function createCustomScope(
		int $actorUserId,
		Context $context,
		string $name,
		array $layout,
		array $accessCodes,
		bool $autoApplyScope = false,
		bool $onAdd = true,
		bool $onUpdate = true,
	): Result
	{
		return $this->customScopeService->create(
			$actorUserId,
			$context,
			$name,
			$layout,
			$accessCodes,
			$autoApplyScope,
			$onAdd,
			$onUpdate,
		);
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function updateCustomScope(
		int $actorUserId,
		Context $context,
		int $scopeId,
		array $changes,
		?int $subjectUserId = null,
	): Result
	{
		return $this->customScopeService->update($actorUserId, $context, $scopeId, $changes, $subjectUserId);
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 */
	public function updateCustomScopeConfig(
		int $actorUserId,
		Context $context,
		int $scopeId,
		array $layout,
		?int $subjectUserId = null,
	): Result
	{
		return $this->customScopeService->updateConfig($actorUserId, $context, $scopeId, $layout, $subjectUserId);
	}

	/**
	 * @param array<int, array{type?: mixed, id?: mixed}|object> $accessCodes
	 */
	public function replaceCustomScopeAccessCodes(
		int $actorUserId,
		Context $context,
		int $scopeId,
		array $accessCodes,
		?int $subjectUserId = null,
	): Result
	{
		return $this->customScopeService->replaceAccessCodes(
			$actorUserId,
			$context,
			$scopeId,
			$accessCodes,
			$subjectUserId,
		);
	}

	public function copyCustomScope(int $actorUserId, Context $context, int $scopeId): Result
	{
		return $this->customScopeService->copy($actorUserId, $context, $scopeId);
	}

	public function deleteCustomScope(
		int $actorUserId,
		Context $context,
		int $scopeId,
		?int $subjectUserId = null,
	): Result
	{
		return $this->customScopeService->delete($actorUserId, $context, $scopeId, $subjectUserId);
	}

	public function getConfiguration(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		ScopeType $scope,
	): Result
	{
		return $this->configurationService->get(
			$actorUserId,
			$targetUserId,
			$context,
			$scope->toConfigurationScope(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 */
	public function setConfiguration(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		ScopeType $scope,
		array $layout,
	): Result
	{
		return $this->configurationService->set(
			$actorUserId,
			$targetUserId,
			$context,
			$scope->toConfigurationScope(),
			$layout,
		);
	}

	public function resetConfiguration(
		int $actorUserId,
		?int $targetUserId,
		Context $context,
		ScopeType $scope,
	): Result
	{
		return $this->configurationService->reset(
			$actorUserId,
			$targetUserId,
			$context,
			$scope->toConfigurationScope(),
		);
	}

	public function getEditScope(
		int $actorUserId,
		Context $context,
		?int $subjectUserId = null,
	): Result
	{
		$result = $this->scopeSelectionService->get($actorUserId, $context, $subjectUserId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$data['scope'] = ScopeType::fromConfigurationScope((string)$data['scope']);

		return (new Result())->setData($data);
	}

	public function setEditScope(
		int $actorUserId,
		Context $context,
		ScopeType $scope,
		?int $scopeId = null,
		?int $subjectUserId = null,
	): Result
	{
		return $this->scopeSelectionService->set(
			$actorUserId,
			$context,
			$scope->toConfigurationScope(),
			$scopeId,
			$subjectUserId,
		);
	}

	public function setCommonForAll(int $actorUserId, Context $context): Result
	{
		return $this->scopeSelectionService->setCommonForAll($actorUserId, $context);
	}
}
