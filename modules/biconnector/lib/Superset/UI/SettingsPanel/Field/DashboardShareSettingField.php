<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

/**
 * The public links setting is applied by its own action at once, so it has no value in the saved settings.
 */
final class DashboardShareSettingField extends EntityEditorField
{
	public const FIELD_NAME = 'DASHBOARD_SHARE_SETTING';
	public const FIELD_ENTITY_EDITOR_TYPE = 'dashboardShareSetting';

	/**
	 * @param array{isBanned: bool, canChange: bool} $state
	 */
	public function __construct(
		string $id,
		private readonly int $dashboardId,
		private readonly array $state,
	)
	{
		parent::__construct($id);
		\Bitrix\Main\UI\Extension::load(['biconnector.share-setting']);
	}

	public function getFieldInitialData(): array
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

	protected function getFieldInfoData(): array
	{
		return [
			'dashboardId' => $this->dashboardId,
			'state' => $this->state,
		];
	}
}
