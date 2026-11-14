<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Public\Features\ManagedTabPresetsFeature;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\PresetReference;
use Bitrix\Mobile\Tab\Preset\Dto\ResolvedTabPreset;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;
use Bitrix\Mobile\Tab\Preset\Model\PresetAssignmentTable;
use Bitrix\Mobile\Tab\Preset\Model\PresetAssignmentV2Table;

final class AssignmentNotificationService
{
	private readonly \Closure $snapshotLoader;

	public function __construct(
		private readonly Context $context,
		private readonly MobileMenuCacheInvalidator $cacheInvalidator,
		private readonly AssignmentNotificationStore $store = new AssignmentNotificationStore(),
		?\Closure $snapshotLoader = null,
	)
	{
		$this->snapshotLoader = $snapshotLoader ?? $this->loadSnapshot(...);
	}

	public function getNotification(
		?ResolvedTabPreset $current = null,
		?array $assignment = null,
		bool $assignmentLoaded = false,
	): ?array
	{
		try
		{
			$notification = $this->getEffectiveNotification($current, $assignment, $assignmentLoaded);
			if ($notification === null || $this->store->get((int)$this->context->userId, (string)$this->context->siteId) === $notification['token'])
			{
				return null;
			}

			return $notification;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	public function acknowledge(string $token, ConnectionPool $connectionPool): Result
	{
		$result = new Result();
		if (!preg_match('/\A[a-f0-9]{64}\z/D', $token))
		{
			return $result->addError(new Error('Invalid assignment notification token', 'INVALID_ASSIGNMENT_NOTIFICATION_TOKEN'));
		}

		$userId = (int)$this->context->userId;
		$siteId = (string)$this->context->siteId;
		$locked = false;
		$masterOnly = false;
		$acknowledged = false;
		try
		{
			try
			{
				$locked = $this->store->lock($userId, $siteId);
				if (!$locked)
				{
					return $result->addError(new Error('Assignment notification is busy', 'ASSIGNMENT_NOTIFICATION_BUSY'));
				}

				$connectionPool->useMasterOnly(true);
				$masterOnly = true;
				$notification = $this->getEffectiveNotification();
				$acknowledged = $this->isSupportedContext() && $this->store->get($userId, $siteId) === $token;
				if (!$acknowledged && $notification !== null && $notification['token'] === $token)
				{
					if (!$this->store->set($userId, $siteId, $token))
					{
						return $result->addError(new Error('Cannot save assignment notification', 'ASSIGNMENT_NOTIFICATION_SAVE_FAILED'));
					}
					$acknowledged = true;
				}
			}
			catch (\Throwable)
			{
				return $result->addError(new Error('Cannot save assignment notification', 'ASSIGNMENT_NOTIFICATION_SAVE_FAILED'));
			}
			finally
			{
				if ($locked)
				{
					$this->store->unlock($userId, $siteId);
				}
			}

			return $result->setData([
				'acknowledged' => $acknowledged,
				'assignmentNotification' => $this->getNotification(),
			]);
		}
		finally
		{
			if ($masterOnly)
			{
				$connectionPool->useMasterOnly(false);
			}
		}
	}

	private function isSupportedContext(): bool
	{
		return Feature::isEnabled(ManagedTabPresetsFeature::class)
			&& (int)$this->context->userId > 0
			&& (string)$this->context->siteId !== ''
			&& !$this->context->isGuest
			&& !$this->context->isCollaber
			&& !$this->context->extranet;
	}

	private function getEffectiveNotification(
		?ResolvedTabPreset $current = null,
		?array $assignment = null,
		bool $assignmentLoaded = false,
	): ?array
	{
		if (!$this->isSupportedContext())
		{
			return null;
		}

		if ($assignmentLoaded && $assignment === null)
		{
			return null;
		}

		$snapshot = ($this->snapshotLoader)($current, $assignment);
		$current = $snapshot['current'] ?? null;
		$assignment = $snapshot['assignment'] ?? null;
		$state = $snapshot['state'] ?? null;
		$config = $snapshot['config'] ?? null;
		if (
			!$current instanceof ResolvedTabPreset
			|| !$assignment
			|| !$state
			|| !is_array($config)
			|| (int)($assignment['ASSIGNER_ID'] ?? 0) <= 0
			|| (int)$assignment['ASSIGNER_ID'] === (int)$this->context->userId
			|| !empty($state['MANUALLY_SET_AT'])
			|| (int)($assignment['ID'] ?? 0) <= 0
			|| (int)($assignment['VERSION'] ?? 0) <= 0
			|| (int)$assignment['VERSION'] !== (int)($state['LAST_ASSIGNMENT_VERSION'] ?? 0)
		)
		{
			return null;
		}

		$isV2 = isset($assignment['PRESET_SOURCE']);
		$reference = $isV2
			? PresetReference::fromAssignmentRow($assignment)
			: PresetReference::fromPresetId('managed:' . (int)($assignment['PRESET_ID'] ?? 0));
		$statePreset = ResolvedTabPreset::fromState($snapshot['appliedConfig'] ?? [], $state);
		if (
			$statePreset === null
			|| !$reference->isAssignable()
			|| $current->source !== $reference->source
			|| $current->presetId !== $reference->key
			|| $statePreset->source !== $reference->source
			|| $statePreset->presetId !== $reference->key
			|| $current->config !== $config
			|| $statePreset->config !== $config
		)
		{
			return null;
		}

		return [
			'token' => hash('sha256', Json::encode([
				(int)$this->context->userId,
				(string)$this->context->siteId,
				$isV2 ? 'v2' : 'legacy',
				(int)$assignment['ID'],
				(int)$assignment['VERSION'],
				$reference->source->value,
				$reference->key,
			])),
			'presetId' => $reference->key,
		];
	}

	// Deliberately permissive: no permission filtering, must never be stricter than the full assignment lookup.
	private function hasAnyActiveAssignment(int $userId, PresetAccessPolicy $accessPolicy): bool
	{
		$departmentIds = $accessPolicy->getDepartmentIdsForAssignmentLookup($userId);
		$directDepartmentIds = $accessPolicy->getDirectDepartmentIdsForUser($userId);

		$v2TargetFilter = [
			[
				'=TARGET_TYPE' => AssignmentTargetType::User->value,
				'=TARGET_ID' => $userId,
			],
		];
		if (!empty($departmentIds))
		{
			$v2TargetFilter[] = [
				'=TARGET_TYPE' => AssignmentTargetType::HrNode->value,
				'@TARGET_ID' => $departmentIds,
			];
		}
		if (!empty($directDepartmentIds))
		{
			$v2TargetFilter[] = [
				'=TARGET_TYPE' => AssignmentTargetType::HrNodeFlat->value,
				'@TARGET_ID' => $directDepartmentIds,
			];
		}

		$hasV2Assignment = PresetAssignmentV2Table::getList([
			'select' => ['ID'],
			'filter' => [
				'=IS_ACTIVE' => 'Y',
				[
					'LOGIC' => 'OR',
					...$v2TargetFilter,
				],
			],
			'limit' => 1,
		])->fetch() !== false;
		if ($hasV2Assignment)
		{
			return true;
		}

		$legacyTargetFilter = [
			[
				'=TARGET_TYPE' => AssignmentTargetType::User->value,
				'=TARGET_ID' => $userId,
			],
		];
		if (!empty($directDepartmentIds))
		{
			$legacyTargetFilter[] = [
				'=TARGET_TYPE' => AssignmentTargetType::HrNode->value,
				'@TARGET_ID' => $directDepartmentIds,
			];
		}

		return PresetAssignmentTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=IS_ACTIVE' => 'Y',
				[
					'LOGIC' => 'OR',
					...$legacyTargetFilter,
				],
			],
			'limit' => 1,
		])->fetch() !== false;
	}

	private function loadSnapshot(?ResolvedTabPreset $current, ?array $assignment): array
	{
		$accessPolicy = new PresetAccessPolicy();
		if ($assignment === null && !$this->hasAnyActiveAssignment((int)$this->context->userId, $accessPolicy))
		{
			return [];
		}

		$manager = Manager::createManaged($this->context);
		$configProvider = new TabPresetConfigProvider($manager);
		$stateService = new UserPresetStateService();
		$assignmentService = new PresetAssignmentV2Service(
			manager: $manager,
			cacheInvalidator: $this->cacheInvalidator,
			accessPolicy: $accessPolicy,
			configProvider: $configProvider,
		);
		$resolver = new PresetResolver(
			manager: $manager,
			cacheInvalidator: $this->cacheInvalidator,
			accessPolicy: $accessPolicy,
			stateService: $stateService,
			configProvider: $configProvider,
			assignmentV2Service: $assignmentService,
		);
		$userId = (int)$this->context->userId;
		$siteId = (string)$this->context->siteId;
		$assignment ??= $resolver->getLatestAssignment($userId);
		if ($assignment === null)
		{
			return [];
		}

		$current ??= (new ManagedActiveTabProvider(
			manager: $manager,
			userId: $userId,
			siteId: $siteId,
			cacheInvalidator: $this->cacheInvalidator,
			accessPolicy: $accessPolicy,
			stateService: $stateService,
			configProvider: $configProvider,
			presetResolver: $resolver,
		))->getResolvedPreset();
		$state = $stateService->getState($userId, $siteId);
		$config = null;
		if ($assignment !== null)
		{
			$reference = isset($assignment['PRESET_SOURCE'])
				? PresetReference::fromAssignmentRow($assignment)
				: PresetReference::fromPresetId('managed:' . (int)$assignment['PRESET_ID']);
			$targetType = AssignmentTargetType::tryFrom((string)($assignment['TARGET_TYPE'] ?? ''));
			if ($targetType === null || !$accessPolicy->canAssignToTarget((int)$assignment['ASSIGNER_ID'], $targetType, (int)$assignment['TARGET_ID']))
			{
				return [];
			}
			$managedPresetId = $reference->getManagedPresetId();
			if ($managedPresetId !== null && !(new ManagedPresetService(
				manager: $manager,
				cacheInvalidator: $this->cacheInvalidator,
				accessPolicy: $accessPolicy,
			))->canAssignPreset((int)$assignment['ASSIGNER_ID'], $managedPresetId))
			{
				return [];
			}
			$availableConfig = $assignmentService->getPresetConfig($reference);
			if ($availableConfig !== null)
			{
				$config = isset($assignment['PRESET_CONFIG_JSON'])
					? $configProvider->normalizePresetConfig(Json::decode($assignment['PRESET_CONFIG_JSON']))
					: $availableConfig;
			}
		}
		$appliedConfig = $state ? $stateService->getAppliedConfig($state) : null;

		return [
			'current' => $current,
			'assignment' => $assignment,
			'state' => $state,
			'config' => $config,
			'appliedConfig' => is_array($appliedConfig) ? $configProvider->normalizePresetConfig($appliedConfig) : null,
		];
	}
}
