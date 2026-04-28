<?php

namespace Bitrix\Crm\Entity\MessageBuilder;

use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * CRM_IMPORT_PAGE_TITLE_LEAD
 * CRM_IMPORT_PAGE_TITLE_DEAL
 * CRM_IMPORT_PAGE_TITLE_CONTACT
 * CRM_IMPORT_PAGE_TITLE_COMPANY
 * CRM_IMPORT_PAGE_TITLE_QUOTE
 * CRM_IMPORT_PAGE_TITLE_SMART_INVOICE
 * CRM_IMPORT_PAGE_TITLE_DYNAMIC
 * CRM_IMPORT_PAGE_TITLE_DEFAULT
 */
final class ImportPageTitleBuilder extends BaseBuilder
{
	protected const MESSAGE_BASE_PREFIX = 'CRM_IMPORT_PAGE_TITLE';

	public function setOrigin(?Origin $origin): self
	{
		if ($origin === null)
		{
			unset($this->replaceList['#ORIGIN#']);

			return $this;
		}

		$this->replaceList['#ORIGIN#'] = $origin->getTitle();

		return $this;
	}

	public function buildCode(): string
	{
		$prefix = self::MESSAGE_BASE_PREFIX;

		return "{$prefix}_{$this->fetchEntityTypeName()}";
	}

	protected function buildDefaultCode(): string
	{
		$prefix = self::MESSAGE_BASE_PREFIX;

		return "{$prefix}_DEFAULT";
	}

	public static function getFilePath(): string
	{
		return __FILE__;
	}
}
