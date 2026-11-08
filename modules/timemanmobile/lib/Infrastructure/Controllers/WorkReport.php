<?php

namespace Bitrix\TimemanMobile\Infrastructure\Controllers;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;
use Bitrix\TimemanMobile\Infrastructure\Controllers\ActionFilters\Attributes\IntranetOnly;

final class WorkReport extends Base
{
	/**
	 * @ajaxAction timemanmobile.WorkReport.getSettings
	 */
	#[CloseSession]
	#[IntranetOnly]
	public function getSettingsAction(): array
	{
		$provider = new SettingsProvider();

		return [
			'isWorkReportOptionEnabledInSettings' => $provider->isReportsEnabledInSettings(),
			'canChangeWorkReportOption' => $provider->canChangeReportsSetting(),
		];
	}

	/**
	 * @ajaxAction timemanmobile.WorkReport.enableWorkReportOption
	 */
	#[IntranetOnly]
	public function enableWorkReportOptionAction(): void
	{
		(new SettingsProvider())->enableReports();
	}

	/**
	 * @ajaxAction timemanmobile.WorkReport.disableWorkReportOption
	 */
	#[IntranetOnly]
	public function disableWorkReportOptionAction(): void
	{
		(new SettingsProvider())->disableReports();
	}
}
