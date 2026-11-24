<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\MainTool;

use Bitrix\Intranet\Internal\Access\MainTool\AccessPolicy;
use Bitrix\Intranet\Internal\Entity\MainTool\Snapshot;
use Bitrix\Intranet\Internal\Integration\Ui\CopilotService;
use Bitrix\Intranet\Internal\Repository\MenuSettingsRepository;
use Bitrix\Intranet\Internal\Service\MainTool\AvailabilityService;
use Bitrix\Intranet\UI\LeftMenu\Preset;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class StateProvider
{
	private MenuSettingsRepository $repository;
	private AvailabilityService $availabilityService;
	private AccessPolicy $accessPolicy;

	public function __construct()
	{
		$this->repository = new MenuSettingsRepository();
		$this->availabilityService = new AvailabilityService();
		$this->accessPolicy = new AccessPolicy();
	}

	public static function isMainToolPickerEnabled(): bool
	{
		return AccessPolicy::isFeatureEnabled();
	}

	public function getMenuSettingsForUser(string $siteId, int $userId): ?array
	{
		if (!AccessPolicy::isFeatureEnabled())
		{
			return null;
		}

		if (!$this->accessPolicy->isUserInAudience($userId))
		{
			return null;
		}

		return $this->getMenuSettings($this->repository->readMenuSettingsSnapshotForUser($siteId, $userId));
	}

	public function getCommonMenuSettings(string $siteId): ?array
	{
		if (!AccessPolicy::isFeatureEnabled())
		{
			return null;
		}

		return $this->getMenuSettings($this->repository->readCommonMenuSettingsSnapshot($siteId));
	}

	private function getMenuSettings(Snapshot $snapshot): array
	{
		$settings = $this->resolveMenuSettings($snapshot);
		$settings['commonPresetId'] = $snapshot->common->presetId;

		return $settings;
	}

	/**
	 * Result data on success: array{
	 *     isMainToolPickerEnabled:bool, availablePresets?:list<array{id:string, canApplyForAll:bool}>,
	 *     currentPresetId?:?string, hasPersonalChoice?:bool, canChangePersonal?:bool, canManageAll?:bool,
	 *     forced?:bool, forcedPresetId?:?string,
	 *     copilotName?:string
	 * }
	 */
	public function getPickerState(): Result
	{
		try
		{
			if (!$this->accessPolicy::isFeatureEnabled())
			{
				$state = ['isMainToolPickerEnabled' => false];

				return (new Result())->setData($state)->addError(new Error(
					(string)Loc::getMessage('MAIN_TOOL_FEATURE_DISABLED'),
					'MAIN_TOOL_FEATURE_DISABLED', $state,
				));
			}

			$siteId = (string)Context::getCurrent()->getSite();
			$userId = (int)CurrentUser::get()->getId();

			if (!$this->accessPolicy->canUsePicker())
			{
				return (new Result())->addError(new Error(
					(string)Loc::getMessage('MAIN_TOOL_ACCESS_DENIED'),
					'MAIN_TOOL_ACCESS_DENIED',
				));
			}

			return (new Result())->setData($this->getPickerStateData($siteId, $userId));
		}
		catch (\Throwable)
		{
			return (new Result())->addError(new Error(
				(string)Loc::getMessage('MAIN_TOOL_SAVE_FAILED'),
				'MAIN_TOOL_SAVE_FAILED',
			));
		}
	}

	private function getPickerStateData(string $siteId, int $userId): array
	{
		$availablePresets = [];
		foreach ($this->availabilityService->getAvailablePresets($userId) as $preset)
		{
			$availablePresets[] = ['id' => $preset->id, 'canApplyForAll' => $preset->canApplyForAll];
		}

		$canManageAll = $this->accessPolicy->canManageAll();
		$snapshot = $this->repository->readMenuSettingsSnapshotForUser($siteId, $userId);
		$menuSettings = $this->resolveMenuSettings($snapshot);

		return [
			'isMainToolPickerEnabled' => true,
			'copilotName' => CopilotService::getName(),
			'availablePresets' => $availablePresets,
			'currentPresetId' => $menuSettings['presetId'],
			'hasPersonalChoice' => $menuSettings['hasPersonalChoice'],
			'canChangePersonal' => !$snapshot->common->forced || $canManageAll,
			'canManageAll' => $canManageAll,
			'forced' => $snapshot->common->forced,
			'forcedPresetId' => $snapshot->common->forced ? $snapshot->common->presetId : null,
		];
	}

	/**
	 * @return array{
	 *     presetId:?string, firstPage:?string, sortData:?array, hasPersonalChoice:bool,
	 *     source:'forced'|'personal'|'common'
	 * }
	 */
	protected function resolveMenuSettings(Snapshot $snapshot): array
	{
		$common = $snapshot->common;
		$personal = $snapshot->personal;
		$hasPersonalChoice = !$common->forced && $personal->presetId !== null;

		if ($common->forced)
		{
			$source = 'forced';
			$presetId = $common->presetId;
			$firstPage = $common->firstPage;
			$sortData = $personal->sortData;
			if (
				$sortData !== null
				&& !Preset\PresetAbstract::isForcedSortAllowed($sortData, $this->getForcedStructure($snapshot))
			)
			{
				$sortData = null;
			}
		}
		elseif ($hasPersonalChoice)
		{
			$source = 'personal';
			$presetId = $personal->presetId;
			$firstPage = $personal->firstPage ?? $common->firstPage;
			$sortData = $personal->sortData ?? $common->sortData;
		}
		else
		{
			$source = 'common';
			$presetId = $common->presetId;
			$firstPage = $personal->firstPage ?? $common->firstPage;
			$sortData = $personal->sortData ?? $common->sortData;
		}

		return [
			'presetId' => $presetId,
			'firstPage' => $firstPage,
			'sortData' => $sortData,
			'hasPersonalChoice' => $hasPersonalChoice,
			'source' => $source,
		];
	}

	protected function getForcedStructure(Snapshot $snapshot): array
	{
		$presetId = $snapshot->common->presetId;

		return Preset\Manager::getPreset(
			$presetId !== null && $presetId !== '' ? $presetId : 'social',
			$snapshot->siteId,
		)->getStructure();
	}

}
