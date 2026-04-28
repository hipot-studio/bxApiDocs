<?php

namespace Bitrix\Crm\Component\DisableHelpers;

final class OldInvoiceReadonlyHelper extends BaseDisableHelper
{
	private const LAST_TIME_SHOWN_FIELD = 'old_invoice_readonly_alert_last_time_shown_date';
	private const LAST_TIME_SHOWN_OPTION_NAME = 'timestamp';

	public function getJsParams(array $context = []): array
	{
		return [
			'contentName' => AlertContent::OLD_INVOICE_READONLY->value,
			'contentOptions' => [
				'lastTimeShownField' => self::LAST_TIME_SHOWN_FIELD,
				'lastTimeShownOptionName' => self::LAST_TIME_SHOWN_OPTION_NAME,
			],
		];
	}

	public function canShowAlert(): bool
	{
		$daysSinceLastTimeShown = $this->getDaysSinceLastTimeShown(
			self::LAST_TIME_SHOWN_FIELD,
			self::LAST_TIME_SHOWN_OPTION_NAME,
		);

		return $daysSinceLastTimeShown === null;
	}
}
