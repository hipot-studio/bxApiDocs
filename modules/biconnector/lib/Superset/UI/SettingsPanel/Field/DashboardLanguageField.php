<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

use Bitrix\BIConnector\Integration\Superset\CultureFormatter;

final class DashboardLanguageField extends EntityEditorField
{
	public const FIELD_NAME = 'DASHBOARD_LANGUAGE';
	public const FIELD_ENTITY_EDITOR_TYPE = 'dashboardLanguage';

	public function getFieldInitialData(): array
	{
		return [
			'currentLanguage' => CultureFormatter::getLanguage(),
		];
	}

	public function getFieldInfoData(): array
	{
		return [];
	}

	public function getName(): string
	{
		return self::FIELD_NAME;
	}

	public function getType(): string
	{
		return self::FIELD_ENTITY_EDITOR_TYPE;
	}
}
