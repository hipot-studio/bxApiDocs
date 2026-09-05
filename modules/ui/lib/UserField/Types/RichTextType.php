<?php

namespace Bitrix\UI\UserField\Types;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserField\Types\StringType;
use Bitrix\UI\Format\BBCode\Converter;
use Bitrix\UI\Format\BBCode\Whitelist;
use CUserTypeManager;

Loc::loadMessages(__FILE__);

class RichTextType extends StringType
{
	public const
		USER_TYPE_ID = 'rich_text',
		RENDER_COMPONENT = 'bitrix:ui.field.richtext';

	public static function getDescription(): array
	{
		return [
			'DESCRIPTION' => Loc::getMessage('USER_TYPE_UI_RICH_TEXT_DESCRIPTION'),
			'BASE_TYPE' => CUserTypeManager::BASE_TYPE_STRING,
		];
	}

	public static function prepareSettings(array $userField): array
	{
		$size = (int)($userField['SETTINGS']['SIZE'] ?? 0);
		$rows = (int)($userField['SETTINGS']['ROWS'] ?? 0);
		$min = (int)($userField['SETTINGS']['MIN_LENGTH'] ?? 0);
		$max = (int)($userField['SETTINGS']['MAX_LENGTH'] ?? 0);

		return [
			'SIZE' => ($size <= 1 ? 20 : ($size > 255 ? 255 : $size)),
			'ROWS' => ($rows <= 1 ? 1 : ($rows > 50 ? 50 : $rows)),
			'MIN_LENGTH' => $min,
			'MAX_LENGTH' => $max,
			'DEFAULT_VALUE' => (string)($userField['SETTINGS']['DEFAULT_VALUE'] ?? ''),
		];
	}

	public static function onBeforeSave(array $userField, $value, $userId = null): string
	{
		return Whitelist::normalize((string)$value);
	}

	public static function onSearchIndex(array $userField): ?string
	{
		$value = parent::onSearchIndex($userField);
		if (!is_string($value) || $value === '')
		{
			return $value;
		}

		return Converter::toPlainText($value);
	}
}
