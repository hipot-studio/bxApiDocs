<?php

namespace Bitrix\Crm\Service\Timeline\Item\AI;

use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Main\Localization\Loc;

final class CallTranscriptResult extends Base
{
	protected function getAITypeId(): string
	{
		return 'CallTranscriptResult';
	}

	protected function getAdditionalIconCode(): string
	{
		return 'a-letter';
	}

	protected function getOpenButtonTitle(): string
	{
		return Loc::getMessage('CRM_TIMELINE_ACTIVITY_AI_TRANSCRIPT_OPEN_BTN');
	}

	protected function getOpenAction(): ?Action
	{
		return (new Action\JsEvent('CallTranscriptResult:Open'))
			->addActionParamInt('activityId', $this->getActivityId())
			->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
			->addActionParamInt('ownerId', $this->getContext()->getEntityId())
			->addActionParamString('languageTitle', $this->getJobResultLanguageTitle())
		;
	}

	protected function getJobResult(): ?Result
	{
		$activityId = $this->getActivityId();
		if ($activityId === null)
		{
			return null;
		}

		return JobRepository::getInstance()
			->getTranscribeCallRecordingResultByActivity($activityId)
		;
	}

	protected function buildJobLanguageBlock(): ?ContentBlock
	{
		return null;
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_ACTIVITY_AI_TRANSCRIPT_RESULT');
	}
}
