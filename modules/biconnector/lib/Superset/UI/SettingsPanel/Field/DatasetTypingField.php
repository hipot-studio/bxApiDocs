<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

use Bitrix\BIConnector\Superset\Config\DatasetSettings;

final class DatasetTypingField extends EntityEditorField
{
	public const FIELD_NAME = 'DATASET_TYPING_ENABLED';
	public const FIELD_ENTITY_EDITOR_TYPE = 'datasetTyping';

	public function getFieldInitialData(): array
	{
		return [
			self::FIELD_NAME => self::isTypingEnabled() ? 'Y' : 'N',
		];
	}

	public function getName(): string
	{
		return self::FIELD_NAME;
	}

	public function getType(): string
	{
		return self::FIELD_ENTITY_EDITOR_TYPE;
	}

	protected function getFieldInfoData(): array
	{
		return [];
	}

	public static function isTypingEnabled(): bool
	{
		return DatasetSettings::isTypingEnabled();
	}
}
