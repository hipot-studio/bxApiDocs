<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Mobile\AppTabs\Menu;
use Bitrix\Mobile\AppTabs\MenuNew;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Feature\MenuFeature;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\PresetReference;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;

final class TabPresetDataProvider
{
	private readonly TabPresetConfigProvider $configProvider;
	private readonly ManagedPresetService $managedService;
	private readonly PresetResolver $presetResolver;
	private readonly PresetAssignmentV2Service $assignmentService;
	private readonly AssignmentRecipientFormatter $recipientFormatter;
	private readonly PortalDefaultPresetService $portalDefaultService;
	private readonly ManagedActiveTabProvider $activeTabProvider;
	private readonly int $userId;
	private readonly string $siteId;

	public function __construct(
		private readonly Manager $manager,
		private readonly Context $context,
		private readonly PresetAccessPolicy $accessPolicy = new PresetAccessPolicy(),
		private readonly UserPresetStateService $stateService = new UserPresetStateService(),
		?TabPresetConfigProvider $configProvider = null,
		?ManagedPresetService $managedService = null,
		?PresetResolver $presetResolver = null,
		?PresetAssignmentV2Service $assignmentService = null,
		?AssignmentRecipientFormatter $recipientFormatter = null,
		?PortalDefaultPresetService $portalDefaultService = null,
		?ManagedActiveTabProvider $activeTabProvider = null,
	)
	{
		$this->userId = (int)$this->context->userId;
		$this->siteId = (string)$this->context->siteId;
		$this->configProvider = $configProvider ?? new TabPresetConfigProvider($this->manager);
		$this->managedService = $managedService
			?? new ManagedPresetService($this->manager, $this->accessPolicy, $this->stateService, $this->configProvider);
		$this->presetResolver = $presetResolver
			?? new PresetResolver($this->manager, $this->accessPolicy, $this->stateService, $this->configProvider);
		$this->assignmentService = $assignmentService
			?? new PresetAssignmentV2Service($this->manager, $this->accessPolicy, $this->configProvider);
		$this->recipientFormatter = $recipientFormatter ?? new AssignmentRecipientFormatter($this->accessPolicy);
		$this->portalDefaultService = $portalDefaultService ?? new PortalDefaultPresetService(
			$this->manager,
			$this->accessPolicy,
			$this->stateService,
			$this->configProvider,
		);
		$this->activeTabProvider = $activeTabProvider ?? new ManagedActiveTabProvider(
			manager: $this->manager,
			userId: $this->userId,
			siteId: $this->siteId,
			accessPolicy: $this->accessPolicy,
			stateService: $this->stateService,
			configProvider: $this->configProvider,
			presetResolver: $this->presetResolver,
			portalDefaultPresetService: $this->portalDefaultService,
		);
	}

	public function getData(): array
	{
		$portalDefaultPresetId = $this->getPortalDefaultPresetId();
		$presetList = $this->manager->getPresetList();
		$current = $this->activeTabProvider->getResolvedPreset();
		$latestAssignment = $this->presetResolver->getLatestAssignment($this->userId);
		$managedPresetRows = $this->managedService->getAvailableManagedPresetsWithLatestAssignment(
			$this->userId,
			$this->siteId,
			$latestAssignment,
			$portalDefaultPresetId,
		);
		$assignmentRecipients = $this->getAssignmentRecipientsByPresetId(
			$this->getAssignmentReferences($presetList, $managedPresetRows),
		);
		$presets = $this->extendBuiltInPresets(
			$presetList,
			$current->presetId,
			$portalDefaultPresetId,
			$assignmentRecipients,
		);
		if ($current->source === PresetSource::Manual && isset($presets['manual']))
		{
			$presets['manual']['tabs'] = $current->config;
		}
		$result = [
			'presets' => [
				'current' => $current->presetId,
				'list' => $presets,
			],
			'tabs' => [
				'current' => $this->formatCurrentTabs($current->config),
				'list' => $this->getAvailableTabs(),
			],
			'capabilities' => $this->getCapabilities(),
		];

		$latestAssignedManagedPresetId = PresetResolver::getManagedPresetIdFromAssignment(
			$latestAssignment,
		);
		foreach ($managedPresetRows as $row)
		{
			$id = (int)$row['ID'];
			$presetId = 'managed:' . $id;
			$result['presets']['list'][$presetId] = $this->managedService->formatPresetItem(
				$row,
				$this->userId,
				$current->presetId === $presetId,
				$portalDefaultPresetId,
				$latestAssignedManagedPresetId === $id,
			);
			$result['presets']['list'][$presetId]['assignmentRecipients'] = $assignmentRecipients[$presetId] ?? [];
		}

		return $result;
	}

	public function formatManagedPreset(array $row): array
	{
		$current = $this->activeTabProvider->getResolvedPreset();
		$latestAssignment = $this->presetResolver->getLatestAssignment($this->userId);
		$latestAssignedManagedPresetId = PresetResolver::getManagedPresetIdFromAssignment($latestAssignment);
		$presetId = 'managed:' . (int)$row['ID'];

		return $this->managedService->formatPresetItem(
			$row,
			$this->userId,
			$current->presetId === $presetId,
			$this->getPortalDefaultPresetId(),
			$latestAssignedManagedPresetId === (int)$row['ID'],
		);
	}

	private function formatCurrentTabs(array $activeTabs): array
	{
		return array_reduce(
			array_keys($activeTabs),
			function ($result, $tabId) use ($activeTabs) {
				$tabInstance = $this->manager->getTabInstance($tabId);
				if ($tabInstance === null)
				{
					return $result;
				}

				$result[$tabId] = [
					'sort' => $activeTabs[$tabId],
					'title' => $tabInstance->getTitle(),
					'canBeRemoved' => $tabInstance->canBeRemoved(),
					'canChangeSort' => $tabInstance->canChangeSort(),
					'iconId' => $tabInstance->getIconId(),
				];

				return $result;
			},
			[],
		);
	}

	private function getAvailableTabs(): array
	{
		return array_reduce(
			$this->manager->getAllTabIDs(),
			function ($result, $tabId) {
				$instance = $this->manager->getTabInstance($tabId);

				$result[$tabId] = [
					'title' => $instance->getTitle(),
					'shortTitle' => $instance->getShortTitle(),
					'iconId' => $instance->getIconId(),
				];

				$isMenuFeatureEnabled = Feature::isEnabled(MenuFeature::class);
				$menuId = $isMenuFeatureEnabled ? (new MenuNew())->getId() : (new Menu())->getId();

				if ($tabId === $menuId)
				{
					if ($isMenuFeatureEnabled)
					{
						$result[$tabId]['imageUrl'] = $instance->getImageUrl();
						$result[$tabId]['name'] = $instance->getLastAndSecondName();
					}

					$result[$tabId]['isAvatarEnabled'] = $isMenuFeatureEnabled;
				}

				return $result;
			},
			[],
		);
	}

	private function extendBuiltInPresets(
		array $presets,
		string $currentPresetId,
		?string $portalDefaultPresetId,
		array $assignmentRecipients,
	): array
	{
		$canManage = $this->accessPolicy->canManagePresets($this->userId);
		$canSetPortalDefault = $this->accessPolicy->canSetPortalDefault($this->userId);
		foreach ($presets as $presetId => $preset)
		{
			$isManual = $presetId === 'manual';
			$presets[$presetId] += [
				'source' => $isManual ? PresetSource::Manual->value : PresetSource::Builtin->value,
				'isPortalDefault' => !$isManual && $presetId === $portalDefaultPresetId,
				'isOwner' => false,
				'canEdit' => $isManual,
				'canAssign' => !$isManual && $canManage,
				'canDelete' => $isManual,
				'canSelfInstall' => true,
				'canSetMainTool' => !$isManual && $canSetPortalDefault,
				'canCustomizeBuiltin' => false,
				'isCurrent' => $presetId === $currentPresetId,
			];
			if (!$isManual)
			{
				$presets[$presetId]['assignmentRecipients'] = $assignmentRecipients[$presetId] ?? [];
			}
		}

		return $presets;
	}

	private function getAssignmentReferences(array $presets, array $managedPresetRows): array
	{
		$references = [];
		foreach (array_keys($presets) as $presetId)
		{
			if ($presetId === 'manual')
			{
				continue;
			}

			$references[$presetId] = PresetReference::fromPresetId($presetId);
		}

		foreach ($managedPresetRows as $row)
		{
			$id = (int)$row['ID'];
			if ($id <= 0 || (int)($row['OWNER_ID'] ?? 0) !== $this->userId)
			{
				continue;
			}

			$presetId = 'managed:' . $id;
			$references[$presetId] = PresetReference::fromPresetId($presetId);
		}

		return $references;
	}

	private function getAssignmentRecipientsByPresetId(array $referencesByPresetId): array
	{
		$targetsByPresetId = $this->assignmentService->getActiveTargetsByReferences(
			$referencesByPresetId,
			$this->userId,
		);

		return $this->recipientFormatter->format($targetsByPresetId);
	}

	private function getCapabilities(): array
	{
		$ownedManagedPresetCount = $this->managedService->getOwnedPresetCount($this->userId);

		return [
			'managedTabPresetsEnabled' => true,
			'canCreateManagedPreset' => true,
			'canManage' => $this->accessPolicy->canManagePresets($this->userId),
			'canSetPortalDefault' => $this->accessPolicy->canSetPortalDefault($this->userId),
			'managedPresetLimit' => ManagedPresetService::MAX_OWNED_PRESET_COUNT,
			'ownedManagedPresetCount' => $ownedManagedPresetCount,
			'isManagedPresetLimitReached' => $ownedManagedPresetCount >= ManagedPresetService::MAX_OWNED_PRESET_COUNT,
			'hrAvailable' => $this->accessPolicy->isHumanResourcesAvailable(),
			'assignmentSelectorOptions' => $this->getAssignmentSelectorOptions(),
		];
	}

	private function getAssignmentSelectorOptions(): array
	{
		if ($this->accessPolicy->canSetPortalDefault($this->userId))
		{
			return [];
		}

		return [
			'departmentIds' => $this->getAssignableLegacyDepartmentIds(),
		];
	}

	private function getAssignableLegacyDepartmentIds(): array
	{
		return array_values(array_filter(array_map(
			fn(int $departmentId): ?int => $this->accessPolicy->getLegacyDepartmentId($departmentId),
			$this->accessPolicy->getHeadDepartmentIds($this->userId),
		)));
	}

	private function getPortalDefaultPresetId(): ?string
	{
		if ($this->context->isGuest || $this->context->isCollaber)
		{
			return null;
		}

		return $this->portalDefaultService->getDefaultPresetId($this->siteId);
	}
}
