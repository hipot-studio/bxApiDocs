<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Model\ManagedPresetTable;

final class ManagedPresetService
{
	public const MAX_OWNED_PRESET_COUNT = 20;
	public const ERROR_OWNED_PRESET_LIMIT_EXCEEDED = 'OWNED_PRESET_LIMIT_EXCEEDED';
	public const ERROR_MESSAGE_OWNED_PRESET_LIMIT_EXCEEDED = 'Managed preset limit exceeded';

	private readonly TabPresetConfigProvider $configProvider;
	private readonly PresetAssignmentService $legacyAssignmentService;
	private readonly PresetAssignmentV2Service $assignmentV2Service;
	private readonly PortalDefaultPresetService $portalDefaultService;
	private readonly PresetResolver $presetResolver;

	public function __construct(
		private readonly Manager $manager,
		private readonly PresetAccessPolicy $accessPolicy = new PresetAccessPolicy(),
		private readonly UserPresetStateService $stateService = new UserPresetStateService(),
		?TabPresetConfigProvider $configProvider = null,
		private readonly MobileMenuCacheInvalidator $cacheInvalidator = new MobileMenuCacheInvalidator(),
		private readonly PresetWriteTransaction $transaction = new PresetWriteTransaction(),
		?PresetAssignmentService $legacyAssignmentService = null,
		?PresetAssignmentV2Service $assignmentV2Service = null,
		?PortalDefaultPresetService $portalDefaultService = null,
		?PresetResolver $presetResolver = null,
	)
	{
		$this->configProvider = $configProvider ?? new TabPresetConfigProvider($this->manager);
		$this->legacyAssignmentService = $legacyAssignmentService
			?? new PresetAssignmentService($this->accessPolicy, transaction: $this->transaction);
		$this->assignmentV2Service = $assignmentV2Service
			?? new PresetAssignmentV2Service(
				$this->manager,
				$this->accessPolicy,
				$this->configProvider,
				transaction: $this->transaction,
			);
		$this->portalDefaultService = $portalDefaultService
			?? new PortalDefaultPresetService(
				$this->manager,
				$this->accessPolicy,
				$this->stateService,
				$this->configProvider,
				transaction: $this->transaction,
			);
		$this->presetResolver = $presetResolver
			?? new PresetResolver(
				$this->manager,
				$this->accessPolicy,
				$this->stateService,
				$this->configProvider,
				assignmentV2Service: $this->assignmentV2Service,
			);
	}

	/**
	 * @throws SystemException
	 */
	public function save(int $ownerId, string $title, array $tabs, ?int $id = null): array
	{
		$preset = null;
		if ($id !== null && $id > 0)
		{
			$preset = $this->getEditablePreset($ownerId, $id);
			if ($preset === null)
			{
				throw new SystemException('Managed preset not found', 404);
			}
		}

		$title = trim($title);
		if ($title === '')
		{
			throw new SystemException('Preset title is required', 422);
		}

		if (($id === null || $id <= 0) && $this->getOwnedPresetCount($ownerId) >= self::MAX_OWNED_PRESET_COUNT)
		{
			throw new SystemException(self::ERROR_MESSAGE_OWNED_PRESET_LIMIT_EXCEEDED, 422);
		}

		try
		{
			$config = (new TabConfigValidator($this->manager))->normalize($tabs);
		}
		catch (ArgumentException $exception)
		{
			throw new SystemException($exception->getMessage(), 422);
		}

		$now = new DateTime();
		$fields = [
			'TITLE' => $title,
			'CONFIG_JSON' => Json::encode($config),
			'UPDATED_AT' => $now,
		];

		if ($id !== null && $id > 0)
		{
			$configChanged = (string)$preset['CONFIG_JSON'] !== (string)$fields['CONFIG_JSON'];

			$this->transaction->run(function () use ($id, $fields, $configChanged, $ownerId): void {
				ManagedPresetTable::update($id, $fields);
				if ($configChanged)
				{
					$this->portalDefaultService->refreshManagedPresetVersion($id);
				}
				if ($configChanged && $this->canAssignPreset($ownerId, $id))
				{
					$this->legacyAssignmentService->refreshByPresetId($id);
					$this->assignmentV2Service->refreshManagedPreset($id);
				}
				if ($configChanged)
				{
					$this->transaction->afterCommit(function () use ($ownerId): void {
						$this->clearUserCache($ownerId);
					});
				}
			});

			return $this->getPresetById($id) ?? [];
		}

		$result = ManagedPresetTable::add($fields + [
			'OWNER_ID' => $ownerId,
			'IS_DELETED' => 'N',
			'CREATED_AT' => $now,
		]);
		if (!$result->isSuccess())
		{
			throw new SystemException(implode('; ', $result->getErrorMessages()), 422);
		}

		return $this->getPresetById((int)$result->getId()) ?? [];
	}

	/**
	 * @throws SystemException
	 */
	public function delete(int $ownerId, int $id, ?string $siteId = null): void
	{
		$preset = $this->getEditablePreset($ownerId, $id);
		if ($preset === null)
		{
			throw new SystemException('Managed preset not found', 404);
		}

		$this->transaction->run(function () use ($id, $siteId, $ownerId): void {
			ManagedPresetTable::update($id, [
				'IS_DELETED' => 'Y',
				'UPDATED_AT' => new DateTime(),
			]);
			$this->legacyAssignmentService->deactivateByPresetId($id);
			$this->assignmentV2Service->deactivateManagedPreset($id);
			if ($siteId !== null)
			{
				$this->portalDefaultService->resetIfManagedPresetDeleted($siteId, $id);
			}
			$this->transaction->afterCommit(function () use ($ownerId): void {
				$this->clearUserCache($ownerId);
			});
		});
	}

	public function getOwnedPresets(int $ownerId): array
	{
		if ($ownerId <= 0)
		{
			return [];
		}

		$rows = [];
		$result = ManagedPresetTable::getList([
			'filter' => [
				'=OWNER_ID' => $ownerId,
				'=IS_DELETED' => 'N',
			],
			'order' => ['UPDATED_AT' => 'DESC', 'ID' => 'DESC'],
		]);
		while ($row = $result->fetch())
		{
			$rows[(int)$row['ID']] = $row;
		}

		return $rows;
	}

	public function getOwnedPresetCount(int $ownerId): int
	{
		if ($ownerId <= 0)
		{
			return 0;
		}

		return (int)ManagedPresetTable::getCount([
			'=OWNER_ID' => $ownerId,
			'=IS_DELETED' => 'N',
		]);
	}

	public function getAvailableManagedPresets(int $userId, string $siteId): array
	{
		$latestAssignment = $this->presetResolver->getLatestAssignment($userId);

		return $this->getAvailableManagedPresetsWithLatestAssignment($userId, $siteId, $latestAssignment);
	}

	public function getAvailableManagedPresetsWithLatestAssignment(
		int $userId,
		string $siteId,
		?array $latestAssignment,
		?string $portalDefaultPresetId = null,
	): array
	{
		$rows = $this->getOwnedPresets($userId);

		$state = $this->stateService->getState($userId, $siteId);
		$currentPresetId = (int)($state['CURRENT_PRESET_ID'] ?? 0);
		if ($currentPresetId > 0 && !isset($rows[$currentPresetId]))
		{
			$row = $this->getPresetById($currentPresetId);
			if ($row !== null)
			{
				$rows[$currentPresetId] = $row;
			}
		}

		$assignedPresetId = PresetResolver::getManagedPresetIdFromAssignment($latestAssignment);
		$this->appendPreset($rows, $assignedPresetId);

		$portalDefaultManagedPresetId = str_starts_with((string)$portalDefaultPresetId, 'managed:')
			? (int)mb_substr($portalDefaultPresetId, 8)
			: 0;
		$this->appendPreset($rows, $portalDefaultManagedPresetId);

		return $rows;
	}

	public function getAllPresets(): array
	{
		$rows = [];
		$result = ManagedPresetTable::getList([
			'filter' => [
				'=IS_DELETED' => 'N',
			],
			'order' => ['UPDATED_AT' => 'DESC', 'ID' => 'DESC'],
		]);
		while ($row = $result->fetch())
		{
			$rows[(int)$row['ID']] = $row;
		}

		return $rows;
	}

	/**
	 * @throws SystemException
	 */
	public function selectForUser(
		int $userId,
		string $siteId,
		int $presetId,
		bool $isManual = true,
		bool $clearCache = true,
	): array
	{
		$available = $this->getAvailableManagedPresets($userId, $siteId);
		$preset = $available[$presetId] ?? null;
		if (!is_array($preset))
		{
			throw new SystemException('Managed preset not found', 404);
		}

		$config = $this->decodeConfig((string)$preset['CONFIG_JSON']);
		if ($config === null)
		{
			throw new SystemException('Managed preset config is invalid', 422);
		}

		if ($isManual)
		{
			$latestAssignment = $this->presetResolver->getLatestAssignment($userId);
			$this->stateService->saveManagedState(
				userId: $userId,
				siteId: $siteId,
				presetId: $presetId,
				config: $config,
				assignmentVersion: (int)($latestAssignment['VERSION'] ?? 0),
				isManual: true,
			);
		}
		else
		{
			$stateSaved = $this->stateService->saveManagedCheckoutIntentState(
				$userId,
				$siteId,
				$presetId,
				$config,
			);
			if (!$stateSaved)
			{
				throw new AutomaticPresetStateWriteRejectedException();
			}
		}
		if ($clearCache)
		{
			$this->clearUserCache($userId);
		}

		return $this->configProvider->normalizePresetConfig($config);
	}

	public function formatPresetItem(
		array $row,
		int $currentUserId,
		bool $isCurrent = false,
		?string $portalDefaultPresetId = null,
		bool $isLastAssigned = false,
	): array
	{
		$config = $this->decodeConfig((string)($row['CONFIG_JSON'] ?? '')) ?? [];
		$id = (int)$row['ID'];
		$presetId = 'managed:' . $id;
		$isOwner = (int)$row['OWNER_ID'] === $currentUserId;
		$canManage = $this->accessPolicy->canManagePresets($currentUserId);
		$canSetPortalDefault = $this->accessPolicy->canSetPortalDefault($currentUserId);
		$canAssign = $isOwner && $canManage;

		return [
			'id' => $presetId,
			'tabs' => $this->configProvider->normalizePresetConfig($config),
			'sort' => 500 + $id,
			'title' => (string)$row['TITLE'],
			'source' => 'managed',
			'isPortalDefault' => $portalDefaultPresetId === $presetId,
			'isOwner' => $isOwner,
			'canEdit' => $isOwner,
			'canAssign' => $canAssign,
			'canDelete' => $isOwner,
			'canSelfInstall' => true,
			'canSetMainTool' => $isOwner && $canSetPortalDefault,
			'canCustomizeBuiltin' => false,
			'isCurrent' => $isCurrent,
			'isLastAssigned' => $isLastAssigned,
		];
	}

	public function getPresetById(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}

		$row = ManagedPresetTable::getList([
			'filter' => [
				'=ID' => $id,
				'=IS_DELETED' => 'N',
			],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}

	public function canAssignPreset(int $userId, int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		return $this->accessPolicy->canManagePresets($userId) && $this->getOwnedPreset($userId, $id) !== null;
	}

	public function isPresetOwner(int $userId, int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		return $this->getOwnedPreset($userId, $id) !== null;
	}

	public function canSetPortalDefaultPreset(int $userId, int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		return $this->accessPolicy->canSetPortalDefault($userId) && $this->getOwnedPreset($userId, $id) !== null;
	}

	private function getOwnedPreset(int $ownerId, int $id): ?array
	{
		$row = ManagedPresetTable::getList([
			'filter' => [
				'=ID' => $id,
				'=OWNER_ID' => $ownerId,
				'=IS_DELETED' => 'N',
			],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}

	private function getEditablePreset(int $userId, int $id): ?array
	{
		return $this->getOwnedPreset($userId, $id);
	}

	private function decodeConfig(string $config): ?array
	{
		try
		{
			$decoded = Json::decode($config);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	private function appendPreset(array &$rows, int $presetId): void
	{
		if ($presetId > 0 && !isset($rows[$presetId]))
		{
			$row = $this->getPresetById($presetId);
			if ($row !== null)
			{
				$rows[$presetId] = $row;
			}
		}
	}

	private function clearUserCache(int $userId): void
	{
		$this->cacheInvalidator->clearUser($userId);
	}
}
