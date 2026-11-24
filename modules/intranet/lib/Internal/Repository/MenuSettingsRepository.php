<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository;

use Bitrix\Intranet\Internal\Entity\MainTool\CommonState;
use Bitrix\Intranet\Internal\Entity\MainTool\PersonalState;
use Bitrix\Intranet\Internal\Entity\MainTool\Snapshot;
use Bitrix\Intranet\UI\LeftMenu\Preset\Manager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\SiteTable;

class MenuSettingsRepository
{
	public function readMenuSettingsSnapshotForUser(string $siteId, int $userId): Snapshot
	{
		if ($userId <= 0)
		{
			throw new ArgumentException('Invalid user.', 'userId');
		}

		return $this->readMenuSettingsSnapshot($siteId, $userId);
	}

	public function readCommonMenuSettingsSnapshot(string $siteId): Snapshot
	{
		return $this->readMenuSettingsSnapshot($siteId, null);
	}

	private function readMenuSettingsSnapshot(string $siteId, ?int $userId): Snapshot
	{
		$site = SiteTable::query()->setSelect(['DIR'])->where('LID', $siteId)->fetch();
		if (!$site)
		{
			throw new ArgumentException('Unknown site.', 'siteId');
		}

		$presetName = 'left_menu_preset_' . $siteId;
		$pageName = 'left_menu_first_page_' . $siteId;
		$sortName = 'left_menu_sorted_items_' . $siteId;
		$personalPreset = $this->normalizePreset($this->getPersonalOption($presetName, $userId));
		$globalPreset = $this->normalizePreset(Option::get('intranet', 'left_menu_preset', '', $siteId));
		$globalPage = $this->normalizeString(Option::get('intranet', 'left_menu_first_page', '', $siteId));

		$forced = $this->isForced($siteId);
		$commonPreset = $globalPreset;
		$commonPage = $globalPage;
		$commonSort = null;

		if (!$forced)
		{
			$commonPreset = $this->normalizePreset(\CUserOptions::GetOption('intranet', $presetName, null, 0))
				?? $globalPreset
				?? 'social';
			$commonPage = $this->normalizeString(\CUserOptions::GetOption('intranet', $pageName, null, 0))
				?? $globalPage;
			$commonSort = $this->normalizeSort(\CUserOptions::GetOption('intranet', $sortName, null, 0));
		}

		$common = new CommonState($forced, $commonPreset, $commonPage, $commonSort);
		$personal = new PersonalState(
			$personalPreset,
			$this->normalizeString(\CUserOptions::GetOption('intranet', $pageName, null, $userId ?? 0)),
			$this->normalizeSort(\CUserOptions::GetOption('intranet', $sortName, null, $userId ?? 0)),
		);

		return new Snapshot($siteId, $userId, (string)$site['DIR'], $common, $personal);
	}

	public function isForced(string $siteId): bool
	{
		return Option::get('intranet', 'left_menu_preset_forced', 'N', $siteId) === 'Y';
	}

	public function savePersonalSelection(string $siteId, int $userId, string $presetId, string $firstPage): bool
	{
		foreach ([
			'left_menu_preset_' => $presetId,
			'left_menu_first_page_' => $firstPage,
			'left_menu_sorted_items_' => [],
		] as $prefix => $value)
		{
			if (!\CUserOptions::SetOption('intranet', $prefix . $siteId, $value, false, $userId))
			{
				return false;
			}
			Manager::clearCache($siteId);
		}

		return true;
	}

	public function saveCommonSelection(string $siteId, string $presetId, string $firstPage): void
	{
		Option::set('intranet', 'left_menu_preset', $presetId, $siteId);
		Manager::clearCache($siteId);
		Option::set('intranet', 'left_menu_first_page', $firstPage, $siteId);
		Manager::clearCache($siteId);
		foreach (['left_menu_preset_', 'left_menu_first_page_', 'left_menu_sorted_items_'] as $prefix)
		{
			\CUserOptions::DeleteOptionsByName('intranet', $prefix . $siteId);
		}
	}

	public function setForced(string $siteId, bool $forced): void
	{
		Option::set('intranet', 'left_menu_preset_forced', $forced ? 'Y' : 'N', $siteId);
		Manager::clearCache($siteId);
	}

	protected function getPersonalOption(string $name, ?int $userId): mixed
	{
		$row = \CUserOptions::GetList([], [
			'USER_ID' => $userId ?? 0,
			'CATEGORY' => 'intranet',
			'NAME' => $name,
			'COMMON' => 'N',
		])->Fetch();

		return $row ? unserialize($row['VALUE'], ['allowed_classes' => false]) : null;
	}

	private function normalizePreset(mixed $value): ?string
	{
		return in_array($value, ['crm', 'tasks', 'social', 'sites', 'sync', 'custom', 'shop', 'boards', 'collab'], true)
			? $value
			: null;
	}

	private function normalizeString(mixed $value): ?string
	{
		return is_string($value) && $value !== '' ? $value : null;
	}

	private function normalizeSort(mixed $value): ?array
	{
		return is_array($value) ? $value : null;
	}
}
