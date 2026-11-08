<?php

namespace Bitrix\TimemanMobile\Public\Providers;

use Bitrix\Timeman\V2\Public\Provider\SettingsProvider as TimemanSettingsProvider;

class SettingsProvider
{
	private TimemanSettingsProvider $provider;

	public function __construct()
	{
		$this->provider = new TimemanSettingsProvider();
	}

	public function isReportsSettingTurnedOn(): bool
	{
		return $this->provider->isReportsEnabled();
	}

	public function isReportGenerationAvailable(): bool
	{
		return $this->provider->isPlanningServiceEnabled();
	}

	public function hasAiReportAccess(): bool
	{
		return $this->provider->hasAiReportAccess();
	}

	public function isReportsEnabledWithAi(): bool
	{
		return $this->provider->isReportsEnabledWithAi();
	}
}