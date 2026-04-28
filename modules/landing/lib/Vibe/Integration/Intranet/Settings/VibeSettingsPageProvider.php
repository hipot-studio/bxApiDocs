<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Integration\Intranet\Settings;

use Bitrix\Main\Localization\Loc;
use Bitrix\Intranet;

/**
 * Class for settings provider
 */
class VibeSettingsPageProvider implements Intranet\Settings\SettingsExternalPageProviderInterface
{
	private int $sort = 100;

	public function getType(): string
	{
		return 'welcome';
	}

	public function getTitle(): string
	{
		return Loc::getMessage('LANDING_INTRANET_SETTINGS_VIBE_TITLE');
	}

	public function getJsExtensions(): array
	{
		return [
			'landing.integration.intranet-setting.vibe-page',
		];
	}

	public function getDataManager(array $data = []): Intranet\Settings\SettingsInterface
	{
		return new VibeSettings($data);
	}

	public function setSort(int $sort): static
	{
		$this->sort = $sort;

		return $this;
	}

	public function getSort(): int
	{
		return $this->sort;
	}
}