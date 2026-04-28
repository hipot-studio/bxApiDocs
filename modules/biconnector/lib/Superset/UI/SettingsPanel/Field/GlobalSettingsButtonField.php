<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

final class GlobalSettingsButtonField extends EntityEditorField
{
	public const FIELD_NAME = 'GLOBAL_SETTINGS_BUTTON';
	public const FIELD_ENTITY_EDITOR_TYPE = 'globalSettingsButton';

	private string $settingsUrl;

	public function __construct(string $id, string $settingsUrl)
	{
		parent::__construct($id);
		$this->settingsUrl = $settingsUrl;
	}

	public function getFieldInitialData(): array
	{
		return [
			'settingsUrl' => $this->settingsUrl,
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
