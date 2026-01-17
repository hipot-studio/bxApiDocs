<?php

namespace Bitrix\Crm\Service\Timeline\Item\AI;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Main\Localization\Loc;

final class TranscriptSummaryResult extends Base
{
	protected function getAITypeId(): string
	{
		return 'TranscriptSummaryResult';
	}

	protected function getAdditionalIconCode(): string
	{
		return 'shape';
	}

	protected function getOpenButtonTitle(): string
	{
		return Loc::getMessage('CRM_TIMELINE_ACTIVITY_AI_TRANSCRIPT_SUMMARY_OPEN_BTN');
	}

	protected function getOpenAction(): ?Action
	{
		return (new Action\JsEvent('TranscriptSummaryResult:Open'))
			->addActionParamInt('activityId', $this->getActivityId())
			->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
			->addActionParamInt('ownerId', $this->getContext()->getEntityId())
			->addActionParamString('languageTitle', $this->getJobResultLanguageTitle())
			->addActionParamString('activityProvider', $this->getActivityProvider())
			->addActionParamString('jobId', $this->getJobId())
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
			->getSummarizeCallTranscriptionResultByActivity($activityId, $this->getJobId())
		;
	}

	protected function buildJobLanguageBlock(): ?ContentBlock
	{
		return null;
	}

	public function getTitle(): ?string
	{
		$providerId = $this->getActivityProvider();
		if ($providerId === Call::getId())
		{
			return Loc::getMessage('CRM_TIMELINE_ACTIVITY_AI_TRANSCRIPT_SUMMARY_RESULT');
		}

		if ($providerId === OpenLine::getId())
		{
			return Loc::getMessage('CRM_TIMELINE_ACTIVITY_AI_TRANSCRIPT_SUMMARY_CHAT_RESULT');
		}

		return null;
	}
}
