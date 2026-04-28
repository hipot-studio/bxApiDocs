<?php

namespace Bitrix\Crm\Entity\MessageBuilder;

use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);


/**
 * CRM_IMPORT_SETTINGS_ITEM_LEAD_CAPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_LEAD_DESCRIPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_DEAL_CAPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_DEAL_DESCRIPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_CAPTION_DEFAULT
 * CRM_IMPORT_SETTINGS_ITEM_DESCRIPTION_DEFAULT
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_CAPTION_CUSTOM
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_DESCRIPTION_CUSTOM
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_CAPTION_GMAIL
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_DESCRIPTION_GMAIL
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_CAPTION_OUTLOOK
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_DESCRIPTION_OUTLOOK
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_CAPTION_YAHOO
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_DESCRIPTION_YAHOO
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_CAPTION_VCARD
 * CRM_IMPORT_SETTINGS_ITEM_CONTACT_DESCRIPTION_VCARD
 * CRM_IMPORT_SETTINGS_ITEM_COMPANY_CAPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_COMPANY_DESCRIPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_QUOTE_CAPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_QUOTE_DESCRIPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_SMART_INVOICE_CAPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_SMART_INVOICE_DESCRIPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_DYNAMIC_CAPTION_NO_ORIGIN
 * CRM_IMPORT_SETTINGS_ITEM_DYNAMIC_DESCRIPTION_NO_ORIGIN
 */
final class ImportSettingsItemBuilder extends BaseBuilder
{
	protected const MESSAGE_BASE_PREFIX = 'CRM_IMPORT_SETTINGS_ITEM';

	public const TYPE_DESCRIPTION = 'DESCRIPTION';
	public const TYPE_CAPTION = 'CAPTION';

	private const NO_ORIGIN = 'NO_ORIGIN';

	protected string $type = self::TYPE_CAPTION;
	protected string $origin = self::NO_ORIGIN;

	public function setOrigin(?Origin $origin): self
	{
		if ($origin === null)
		{
			$this->origin = self::NO_ORIGIN;

			return $this;
		}

		$this->origin = mb_strtoupper($origin->value);

		return $this;
	}

	public function setType(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function buildCode(): string
	{
		$prefix = self::MESSAGE_BASE_PREFIX;

		return "{$prefix}_{$this->fetchEntityTypeName()}_{$this->type}_{$this->origin}";
	}

	protected function buildDefaultCode(): string
	{
		$prefix = self::MESSAGE_BASE_PREFIX;

		return "{$prefix}_{$this->type}_DEFAULT";
	}

	public static function getFilePath(): string
	{
		return __FILE__;
	}
}
