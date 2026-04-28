<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

use Bitrix\BIConnector\Configuration\DataTimezone;

final class TimeZoneField extends EntityEditorField
{
	public const FIELD_NAME = 'TIME_ZONE';
	public const FIELD_ENTITY_EDITOR_TYPE = 'timeZone';

	public function getFieldInitialData(): array
	{
		$timezoneList = \CTimeZone::GetZones();

		return [
			'currentTimeZone' => $timezoneList[DataTimezone::getTimezone()] ?? '',
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
