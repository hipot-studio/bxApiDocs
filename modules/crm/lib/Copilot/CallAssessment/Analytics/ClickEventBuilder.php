<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Analytics;

enum ClickEventBuilder: string
{
	case BiReport = 'bi_report_button';
	case ScriptWizard = 'script_wizard';
	case SummaryWizard = 'summary_wizard';

	public function jsBuilderClass(): string
	{
		return match ($this)
		{
			self::BiReport => 'BX.Crm.Integration.Analytics.Builder.CallAssessment.BiReportEvent',
			self::ScriptWizard => 'BX.Crm.Integration.Analytics.Builder.CallAssessment.ScriptWizardEvent',
			self::SummaryWizard => 'BX.Crm.Integration.Analytics.Builder.CallAssessment.SummaryWizardEvent',
		};
	}
}
