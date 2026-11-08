<?php

namespace Bitrix\Crm\Settings;

use CCrmOwnerType;

class CopilotSettings
{
	private static ?self $current = null;

	private array $fillFieldsSettings;

	public function __construct()
	{
		$this->fillFieldsSettings = [
			CCrmOwnerType::Lead => new BooleanSetting('copilot_fill_fields_lead_enabled', true),
			CCrmOwnerType::Deal => new BooleanSetting('copilot_fill_fields_deal_enabled', true),
			CCrmOwnerType::Contact => new BooleanSetting('copilot_fill_fields_contact_enabled', true),
			CCrmOwnerType::Company => new BooleanSetting('copilot_fill_fields_company_enabled', true),
		];
	}

	public static function getCurrent(): self
	{
		if (self::$current === null)
		{
			self::$current = new self();
		}

		return self::$current;
	}

	public function isFillFieldsEnabledFor(int $entityTypeId): bool
	{
		return isset($this->fillFieldsSettings[$entityTypeId])
			? $this->fillFieldsSettings[$entityTypeId]->get()
			: true;
	}

	public function setFillFieldsEnabledFor(int $entityTypeId, bool $isEnabled): void
	{
		if (isset($this->fillFieldsSettings[$entityTypeId]))
		{
			$this->fillFieldsSettings[$entityTypeId]->set($isEnabled);
		}
	}

	public function getFillFieldsEntityTypeIds(): array
	{
		return array_keys($this->fillFieldsSettings);
	}
}
