<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario;

enum ScenarioCode: string
{
	case CALL_ASSESSMENT = 'call_assessment';
	case SUMMARY_HISTORY = 'summary_history';
	case TRANSCRIPT = 'transcript';
}
