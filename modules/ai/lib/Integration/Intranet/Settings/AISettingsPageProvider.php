<?php

namespace Bitrix\AI\Integration\Intranet\Settings;

use Bitrix\Main\Localization\Loc;
use Bitrix\Intranet;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

/**
 * Class for settings provider
 */
class AISettingsPageProvider implements Intranet\Settings\SettingsExternalPageProviderInterface
{
	protected const SORT = 100;

	public function getType(): string
	{
		return 'ai';
	}

	public function getTitle(): string
	{
		return Loc::getMessage('AI_SETTINGS_PAGE_TITLE_MSGVER_1', [
			'#COPILOT_NAME#' => $this->getCopilotName(),
		]);
	}

	public function getJsExtensions(): array
	{
		return [
			'ai.integration.intranet-settings'
		];
	}

	public function getDataManager(array $data = []): Intranet\Settings\SettingsInterface
	{
		return new AISetting($data);
	}

	public function getSort(): int
	{
		return self::SORT;
	}

	private function getCopilotName(): string
	{
		return (new CopilotNameService())->getCopilotName();
	}
}
