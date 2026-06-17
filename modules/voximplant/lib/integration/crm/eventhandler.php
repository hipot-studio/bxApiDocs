<?php

namespace Bitrix\Voximplant\Integration\Crm;

use Bitrix\Voximplant\Agent\StatisticReindexer;

class EventHandler
{
	private const LEAD_CAPTION_FIELDS = [
		'TITLE', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'COMPANY_TITLE', 'HONORIFIC',
	];
	private const CONTACT_CAPTION_FIELDS = [
		'NAME', 'LAST_NAME', 'SECOND_NAME', 'HONORIFIC',
	];
	private const COMPANY_CAPTION_FIELDS = ['TITLE'];
	private const DEAL_CAPTION_FIELDS = ['TITLE'];

	public static function onAfterLeadUpdate(array $fields): void
	{
		static::scheduleIfCaptionChanged('LEAD', $fields, self::LEAD_CAPTION_FIELDS);
	}

	public static function onAfterContactUpdate(array $fields): void
	{
		static::scheduleIfCaptionChanged('CONTACT', $fields, self::CONTACT_CAPTION_FIELDS);
	}

	public static function onAfterCompanyUpdate(array $fields): void
	{
		static::scheduleIfCaptionChanged('COMPANY', $fields, self::COMPANY_CAPTION_FIELDS);
	}

	public static function onAfterDealUpdate(array $fields): void
	{
		static::scheduleIfCaptionChanged('DEAL', $fields, self::DEAL_CAPTION_FIELDS);
	}

	private static function scheduleIfCaptionChanged(string $entityType, array $fields, array $captionFields): void
	{
		return;
	}
}
