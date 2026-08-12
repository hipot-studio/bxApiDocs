<?php

namespace Bitrix\Call\Analytics;

use Bitrix\Main\Error;
use Bitrix\Call\Analytics\Event\FollowUpEvent;
use Bitrix\Call\Analytics\Event\FollowUpTaskEvent;
use Bitrix\Call\ControllerClient;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Integration\AI\Task\AITask;

/**
 * @internal
 */
class FollowUpAnalytics extends AbstractAnalytics
{
	public function addFollowUpResultMessage(): self
	{
		$this->async(function ()
		{
			(new FollowUpEvent('send_message', $this->call))
				->setType('follow_up')//st[type]
				->setStatus('summary')
				->send()
			;
		});

		return $this;
	}

	public function addFollowUpErrorMessage(string $errorCode): self
	{
		$this->async(function () use ($errorCode)
		{
			(new FollowUpEvent('send_message', $this->call))
				->setType('processing_error')//st[type]
				->setStatus('processing_error_' . $errorCode)
				->send()
			;
		});

		return $this;
	}

	public function addStopRecording(): self
	{
		$this->async(function ()
		{
			(new FollowUpTaskEvent('ai_record_stop', $this->call))
				->setStatus('success')
				->send()
			;
		});

		return $this;
	}

	public function addErrorRecording(string $errorCode): self
	{
		$this->async(function () use ($errorCode)
		{
			(new FollowUpTaskEvent('ai_record_stop', $this->call))
				->setStatus('error_'. $errorCode)
				->send()
			;
		});

		return $this;
	}

	public function addGotEmptyRecord(): self
	{
		$this->async(function ()
		{
			(new FollowUpTaskEvent('blank_record', $this->call))
				->setSection('call_followup')
				->setP4('recordDuration_0')
				->send()
			;
		});

		return $this;
	}

	public function addAITaskLunch(AITask $task): self
	{
		$eventType = match ($task->getAISenseType())
		{
			SenseType::TRANSCRIBE->value => 'audio_to_text',
			SenseType::SUMMARY->value => 'meeting_summarization',
			SenseType::OVERVIEW->value => 'meeting_overview',
			SenseType::INSIGHTS->value => 'meeting_insights',
			SenseType::EVALUATION->value => 'meeting_evaluation',
			default => strtolower(SenseType::EVALUATION->value),
		};
		$this->async(function () use ($eventType)
		{
			(new FollowUpTaskEvent($eventType, $this->call))
				->setSection('success')
				->send()
			;
		});

		return $this;
	}

	public function addAITaskFailed(AITask $task, string $errorCode): self
	{
		$eventType = match ($task->getAISenseType())
		{
			SenseType::TRANSCRIBE->value => 'audio_to_text',
			SenseType::SUMMARY->value => 'meeting_summarization',
			SenseType::OVERVIEW->value => 'meeting_overview',
			SenseType::INSIGHTS->value => 'meeting_insights',
			SenseType::EVALUATION->value => 'meeting_evaluation',
			default => strtolower(SenseType::EVALUATION->value),
		};
		$this->async(function () use ($eventType, $errorCode)
		{
			(new FollowUpTaskEvent($eventType, $this->call))
				->setSection('error_'. $errorCode)
				->send()
			;
		});

		return $this;
	}

	/**
	 * Send telemetry data about AI follow-up or call events to callcontroller.
	 * @param AITask|null $source Task object for AI events or null for general call events.
	 * @param string $status Allows 'success' or 'error'.
	 * @param string|null $errorCode
	 * @param string $event Event type identifier.
	 * @param Error|null $error
	 * @return self
	 */
	public function sendTelemetry(
		AITask|null $source,
		string $status,
		?string $errorCode = null,
		string $event = 'task_status',
		Error|null $error = null
	): self
	{
		$sourceData = [];
		if ($source instanceof AITask)
		{
			$sourceData['taskId'] = $source->getId();
			$sourceData['taskType'] = $source->getAISenseType();
			$sourceData['taskHash'] = $source->getHash();
			$sourceData['taskLanguage'] = $source->getLanguageId();
		}

		$this->baseSendTelemetry($sourceData, $status, $errorCode, $event, $error);

		return $this;
	}

	protected function sendTelemetryRequest(array $telemetryData): void
	{
		(new ControllerClient())->sendAIFollowUpTelemetry($telemetryData);
	}
}
