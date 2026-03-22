<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage\AI;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Localization\Loc;

final class RecordTranscriptSummaryStarted extends Base
{
	public function getType(): string
	{
		return 'RecordTranscriptSummaryStarted';
	}

	public function getTitle(): ?string
	{
		if ($this->isCallAssociated())
		{
			return Loc::getMessage(
				'CRM_TIMELINE_LOG_TRANSCRIPT_SUMMARY_STARTED',
				['#COPILOT_NAME#' => AIManager::getCopilotName()]
			);
		}

		if ($this->isOpenLineAssociated())
		{
			return Loc::getMessage(
				'CRM_TIMELINE_LOG_TRANSCRIPT_SUMMARY_CHAT_STARTED',
				['#COPILOT_NAME#' => AIManager::getCopilotName()]
			);
		}

		return null;
	}
}
