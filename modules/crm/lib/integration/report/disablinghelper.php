<?php

namespace Bitrix\Crm\Integration\Report;

use Bitrix\Crm\Integration\Report\AnalyticBoard\MyReports\ActivityAnalyticBoard;
use Bitrix\Crm\Integration\Report\AnalyticBoard\MyReports\CompanyAnalyticBoard;
use Bitrix\Crm\Integration\Report\AnalyticBoard\MyReports\ContactAnalyticBoard;
use Bitrix\Crm\Integration\Report\AnalyticBoard\MyReports\CrmStartAnalyticBoard;
use Bitrix\Crm\Integration\Report\AnalyticBoard\MyReports\DealAnalyticBoard;
use Bitrix\Crm\Integration\Report\AnalyticBoard\MyReports\InvoiceAnalyticBoard;
use Bitrix\Crm\Integration\Report\AnalyticBoard\MyReports\LeadAnalyticBoard;
use Bitrix\Main\Loader;
use CUserOptions;

class DisablingHelper
{
	private static ?bool $areMyReportsInDemoMode = null;

	public static function areMyReportsInDemoMode(): bool
	{
		if (!is_null(self::$areMyReportsInDemoMode))
		{
			return self::$areMyReportsInDemoMode;
		}

		if (!Loader::includeModule('report'))
		{
			return true;
		}

		$fieldNames = [
			DealAnalyticBoard::REPORT_GUID,
			ContactAnalyticBoard::REPORT_GUID,
			CompanyAnalyticBoard::REPORT_GUID,
			LeadAnalyticBoard::REPORT_GUID,
			InvoiceAnalyticBoard::REPORT_GUID,
			CrmStartAnalyticBoard::REPORT_GUID,
			ActivityAnalyticBoard::REPORT_GUID,
		];

		foreach ($fieldNames as $fieldName)
		{
			$option = CUserOptions::GetOption('crm.widget_panel', $fieldName);

			if (isset($option['enableDemoMode']) && $option['enableDemoMode'] === 'N')
			{
				return self::$areMyReportsInDemoMode = false;
			}
		}

		return self::$areMyReportsInDemoMode = true;
	}
}
