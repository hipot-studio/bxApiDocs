<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\MainTool;

use Bitrix\Crm\Settings\DealSettings;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Intranet\Integration\Crm;
use Bitrix\Intranet\Site\FirstPage\MainFirstPage;
use Bitrix\Intranet\UI\LeftMenu\Preset;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Web\Json;

/** @phpstan-import-type Snapshot from Repository */
class StateProvider
{
	public function __construct(
		private Repository $repository,
		private AvailabilityProvider $availabilityProvider,
		private AccessPolicy $accessPolicy,
	)
	{
	}

	/**
	 * @return array{
	 *     isMainToolPickerEnabled:bool, availablePresets?:list<array{id:string, canApplyForAll:bool}>,
	 *     currentPresetId?:?string, hasPersonalChoice?:bool, canChangePersonal?:bool, canManageAll?:bool,
	 *     forced?:bool, forcedPresetId?:?string,
	 *     renderRevision?:string, url?:?string, startUrl?:string, autoEligible?:bool
	 * }
	 */
	public function getState(string $siteId, int $userId): array
	{
		if (!$this->accessPolicy::isFeatureEnabled())
		{
			return ['isMainToolPickerEnabled' => false];
		}
		if (!$this->accessPolicy->canUsePicker($userId))
		{
			throw new AccessDeniedException();
		}

		$snapshot = $this->repository->readSnapshot($siteId, $userId);
		$effective = $this->resolve($snapshot);
		$presets = $this->availabilityProvider->getAvailablePresets($siteId, $userId);
		$canManageAll = $this->accessPolicy->canManageAll($userId);
		$canChangePersonal = !$snapshot['common']['forced'] || $canManageAll;
		$url = $this->getSelectedUrl($snapshot, $effective, $presets);

		return [
			'isMainToolPickerEnabled' => true,
			'availablePresets' => $presets,
			'currentPresetId' => $effective['presetId'],
			'hasPersonalChoice' => $effective['hasPersonalChoice'],
			'canChangePersonal' => $canChangePersonal,
			'canManageAll' => $canManageAll,
			'forced' => $snapshot['common']['forced'],
			'forcedPresetId' => $snapshot['common']['forced'] ? $snapshot['common']['presetId'] : null,
			'renderRevision' => $this->getRenderRevision($snapshot, $effective),
			'url' => $url,
			'startUrl' => $this->getStartUrl($snapshot, $url, $presets),
			'autoEligible' => $canChangePersonal
				&& !$effective['hasPersonalChoice']
				&& !$snapshot['personal']['shown']
				&& $presets !== [],
		];
	}

	/**
	 * @param Snapshot $snapshot
	 * @return array{
	 *     presetId:?string, firstPage:?string, sortData:?array, hasPersonalChoice:bool,
	 *     source:'forced'|'personal'|'common'
	 * }
	 */
	public function resolve(array $snapshot): array
	{
		$common = $snapshot['common'];
		$personal = $snapshot['personal'];
		$hasPersonalChoice = !$common['forced']
			&& $personal['hasPersonalChoice']
			&& $personal['presetId'] !== null
		;

		if ($common['forced'])
		{
			$source = 'forced';
			$presetId = $common['presetId'];
			$firstPage = $common['firstPage'];
			$sortData = $personal['sortData'];
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
			$presetId = $personal['presetId'];
			$firstPage = $personal['firstPage'] ?? $common['firstPage'];
			$sortData = $personal['sortData'] ?? $common['sortData'];
		}
		else
		{
			$source = 'common';
			$presetId = $common['presetId'];
			$firstPage = $personal['firstPage'] ?? $common['firstPage'];
			$sortData = $personal['sortData'] ?? $common['sortData'];
		}

		return [
			'presetId' => $presetId,
			'firstPage' => $firstPage,
			'sortData' => $sortData,
			'hasPersonalChoice' => $hasPersonalChoice,
			'source' => $source,
		];
	}

	protected function getForcedStructure(array $snapshot): array
	{
		$presetId = $snapshot['common']['presetId'];

		return Preset\Manager::getPreset(
			$presetId !== null && $presetId !== '' ? $presetId : 'social',
			$snapshot['siteId'],
		)->getStructure();
	}

	protected function getRenderRevision(array $snapshot, array $effective): string
	{
		return hash('sha256', Json::encode([
			'isMainToolPickerEnabled' => true,
			'siteId' => $snapshot['siteId'],
			'userId' => $snapshot['userId'],
			'forced' => $snapshot['common']['forced'],
			'effective' => $effective,
		]));
	}

	protected function getSelectedUrl(array $snapshot, array $effective, array $presets): ?string
	{
		$url = $this->normalizeUrl($effective['firstPage'], $snapshot['userId']);
		$availableIds = array_column($presets, 'id');
		if ($url === null && in_array($effective['presetId'], $availableIds, true))
		{
			$url = $this->normalizeUrl($this->availabilityProvider->getFirstPage(
				$snapshot['siteId'],
				$snapshot['userId'],
				$effective['presetId'],
				false,
			), $snapshot['userId']);
		}
		if ($url === null)
		{
			return null;
		}

		$path = $url;
		$sitePrefix = rtrim($snapshot['siteDir'], '/');
		if ($sitePrefix !== '' && str_starts_with($path, $sitePrefix . '/'))
		{
			$path = substr($path, strlen($sitePrefix));
		}
		$requiredPreset = match (true)
		{
			preg_match('~^/crm(?:/|\?|$)~i', $path) === 1 => 'crm',
			preg_match('~^/sites(?:/|\?|$)~i', $path) === 1 => 'sites',
			preg_match('~^/sync(?:/|\?|$)~i', $path) === 1 => 'sync',
			preg_match('~^/company/personal/user/\d+/tasks(?:/|\?|$)~i', $path) === 1 => 'tasks',
			default => null,
		};
		if ($requiredPreset !== null && !in_array($requiredPreset, $availableIds, true))
		{
			return null;
		}
		if ($requiredPreset === 'crm')
		{
			if (!Crm::getInstance()->canReadSomeItemsInCrm())
			{
				return null;
			}
			if (preg_match('~^/crm/lead/~i', $path) === 1 && !LeadSettings::isEnabled())
			{
				return DealSettings::getCurrent()->getCurrentListViewID() === DealSettings::VIEW_KANBAN
					? $snapshot['siteDir'] . 'crm/deal/kanban/'
					: \CCrmOwnerType::GetListUrl(\CCrmOwnerType::Deal)
				;
			}
		}

		return $url;
	}

	protected function getStartUrl(array $snapshot, ?string $url, array $presets): string
	{
		$vibe = $this->getVibeUrl();
		if ($vibe !== null)
		{
			return $vibe;
		}
		if ($url !== null)
		{
			return $url;
		}

		$availableIds = array_column($presets, 'id');
		foreach (array_unique(array_merge(['tasks', 'social'], $availableIds)) as $presetId)
		{
			if (!in_array($presetId, $availableIds, true))
			{
				continue;
			}
			$fallback = $this->getSelectedUrl($snapshot, ['presetId' => $presetId, 'firstPage' => null], $presets);
			if ($fallback !== null)
			{
				return $fallback;
			}
		}

		return $snapshot['siteDir'] . (
			ModuleManager::isModuleInstalled('im') && \CBXFeatures::IsFeatureEnabled('WebMessenger')
				? 'online/'
				: 'stream/'
		);
	}

	protected function getVibeUrl(): ?string
	{
		$page = new MainFirstPage();

		return $page->isEnabled() ? $page->getUri()->getUri() : null;
	}

	private function normalizeUrl(?string $url, int $userId): ?string
	{
		if (
			$url === null
			|| !str_starts_with($url, '/')
			|| str_starts_with($url, '//')
			|| str_contains($url, '\\')
			|| preg_match('/[\x00-\x20\x7f]/', $url) === 1
			|| preg_match('~^/(?:index\.php)?(?:\?.*)?$~i', $url) === 1
			|| str_starts_with($url, '/company/personal/mail/')
			|| strlen($url) > 2048
		)
		{
			return null;
		}

		return str_replace('#USER_ID#', (string)$userId, $url);
	}
}
