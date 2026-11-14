<?php

namespace Bitrix\Call\Analytics;

use Bitrix\Main\Error;
use Bitrix\Call\Analytics\Event\FollowUpEvent;
use Bitrix\Call\Analytics\Event\FollowUpTaskEvent;
use Bitrix\Call\ControllerClient;
use Bitrix\Call\DTO\FollowUpRestartRequest;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Integration\AI\Task\AITask;

/**
 * @internal
 */
class FollowUpAnalytics extends AbstractAnalytics
{
	/**
	 * Events of the expectation agent asking the service for the files of a call
	 * {@see \Bitrix\Call\Integration\AI\CallAIService::expectCallAiTask()}. Declared here because
	 * the registry of event values is shared with every other follow-up event of this channel and a
	 * new value must not collide with one of them.
	 * "No files" and "the service did not answer" must never collapse into one value.
	 */
	public const
		EVENT_TRACKS_REQUESTED = 'tracks_requested',
		EVENT_TRACKS_RECEIVED = 'tracks_received',
		EVENT_TRACKS_ABSENT = 'tracks_absent',
		EVENT_TRACKS_SERVICE_UNAVAILABLE = 'tracks_service_unavailable'
	;

	/**
	 * Events of a restart of the analysis asked for from the admin panel of the media service
	 * {@see \Bitrix\Call\Controller\CallController::restartFollowUpAction()}. The value of the
	 * event is itself the mark of a repeat - none of the three collides with another value of this
	 * shared channel - so nothing else has to carry that fact.
	 *
	 * A restart reports itself on success as well and not on refusal alone: the question these
	 * answer is how often the repeat helps, which a channel of refusals cannot tell.
	 */
	public const
		EVENT_RESTART_REQUESTED = 'ai_restart_requested',
		EVENT_RESTART_STARTED = 'ai_restart_started',
		EVENT_RESTART_REFUSED = 'ai_restart_refused'
	;

	/**
	 * The mode of the request, by the five ways of asking for a restart (DTO-01 of the SDD).
	 */
	public const
		MODE_RESTART_FULL = 'restart_full',
		MODE_RESTART_STORED = 'restart_stored',
		MODE_RESTART_PARTIAL = 'restart_partial',
		MODE_RESTART_PURGE = 'restart_purge',
		MODE_RESTART_FAILED = 'restart_failed'
	;

	/**
	 * Which of the five ways of asking this request is. The order of the tests is the order the
	 * marks narrow the meaning in: the mode of the failed types and the erasing one are told by a
	 * mark of their own, a new delivery of the recording is the full one, and a named list makes it
	 * partial - what is left asks for everything on the copy the portal holds.
	 */
	public static function restartMode(FollowUpRestartRequest $request): string
	{
		return match (true)
		{
			$request->onlyFailed => self::MODE_RESTART_FAILED,
			$request->purge => self::MODE_RESTART_PURGE,
			$request->source === FollowUpRestartRequest::SOURCE_DELIVERY => self::MODE_RESTART_FULL,
			$request->normalizedSenses() !== [] => self::MODE_RESTART_PARTIAL,
			default => self::MODE_RESTART_STORED,
		};
	}

	/**
	 * The set of types as one character per type in the canonical order of the enumeration.
	 *
	 * Fixed length on purpose: the value must not grow with the number of types in the request, and
	 * this one changes only when the module gains a type of result - once, for the whole channel.
	 * Read on the side of the service the same way its other composite statuses are.
	 *
	 * @param string[] $senseValues
	 */
	public static function sensesMask(array $senseValues): string
	{
		$mask = '';
		foreach (SenseType::cases() as $senseType)
		{
			$mask .= in_array($senseType->value, $senseValues, true) ? '1' : '0';
		}

		return $mask;
	}

	/**
	 * The request passed the guard of the action: the set it asks about is what it is reported by.
	 *
	 * @param string[] $requestedSenses
	 */
	public function addRestartRequested(string $mode, array $requestedSenses): self
	{
		return $this->sendRestartEvent(self::EVENT_RESTART_REQUESTED, $mode, 'senses_' . self::sensesMask($requestedSenses));
	}

	/**
	 * The set really went into the queue.
	 *
	 * @param string[] $startedSenses
	 */
	public function addRestartStarted(string $mode, array $startedSenses): self
	{
		return $this->sendRestartEvent(self::EVENT_RESTART_STARTED, $mode, 'senses_' . self::sensesMask($startedSenses));
	}

	/**
	 * The request was refused before a single task was created, under the code the caller got.
	 */
	public function addRestartRefused(string $mode, string $errorCode): self
	{
		return $this->sendRestartEvent(self::EVENT_RESTART_REFUSED, $mode, 'refused_' . $errorCode);
	}

	private function sendRestartEvent(string $event, string $mode, string $status): self
	{
		$this->async(function () use ($event, $mode, $status)
		{
			(new FollowUpEvent($event, $this->call))
				->setType($mode)//st[type]
				->setStatus($status)
				->send()
			;
		});

		return $this;
	}

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
