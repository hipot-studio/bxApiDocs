<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;

final class LegacyTabPresetSnapshotWriter implements LegacyTabPresetSnapshotStore
{
	public function __construct(
		private readonly int $userId,
		private readonly string $siteId,
		private readonly ?\Closure $optionSetter = null,
		private readonly ?\Closure $manualMarkerSetter = null,
	)
	{
	}

	public function saveCustomConfig(array $config, bool $isManual): void
	{
		$this->saveOption('tabs_' . $this->userId, Json::encode($config));
		$this->saveOption('tabs_preset_' . $this->userId, 'manual');
		$this->markAsManualIfNeeded($isManual);
	}

	public function savePreset(string $presetId, bool $isManual): void
	{
		$this->saveOption('tabs_preset_' . $this->userId, $presetId);
		$this->saveOption('tabs_' . $this->userId, '');
		$this->markAsManualIfNeeded($isManual);
	}

	private function saveOption(string $name, string $value): void
	{
		if ($this->optionSetter !== null)
		{
			($this->optionSetter)($name, $value);
		}
		else
		{
			Option::set('mobile', $name, $value, $this->siteId);
		}

		if ($this->getSiteOption($name) !== $value)
		{
			throw new SystemException('Unable to save legacy tab preset option');
		}
	}

	private function markAsManualIfNeeded(bool $isManual): void
	{
		if (!$isManual)
		{
			return;
		}

		$success = $this->manualMarkerSetter !== null
			? ($this->manualMarkerSetter)($this->userId)
			: \CUserOptions::SetOption('mobile', 'tabs_preset_manually_set', 'Y', false, $this->userId);
		if ($success !== true || $this->getManualMarker() !== serialize('Y'))
		{
			throw new SystemException('Unable to save legacy tab preset marker');
		}
	}

	private function getSiteOption(string $name): ?string
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$row = $connection->query(sprintf(
			"SELECT VALUE FROM b_option_site WHERE MODULE_ID = 'mobile' AND NAME = '%s' AND SITE_ID = '%s'",
			$sqlHelper->forSql($name),
			$sqlHelper->forSql($this->siteId),
		))->fetch();

		return is_array($row) ? (string)$row['VALUE'] : null;
	}

	private function getManualMarker(): ?string
	{
		$connection = Application::getConnection();
		$row = $connection->query(sprintf(
			"SELECT VALUE FROM b_user_option WHERE USER_ID = %d AND CATEGORY = 'mobile' AND NAME = 'tabs_preset_manually_set' AND COMMON = 'N'",
			$this->userId,
		))->fetch();

		return is_array($row) ? (string)$row['VALUE'] : null;
	}
}
