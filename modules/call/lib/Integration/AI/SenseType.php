<?php

namespace Bitrix\Call\Integration\AI;

enum SenseType: string
{
	case TRANSCRIBE = 'transcribe';
	case OVERVIEW = 'overview';
	case SUMMARY = 'summary';
	case INSIGHTS = 'insights';
	case EVALUATION = 'evaluation';

	/**
	 * @return string|Task\AITask
	 */
	public function getTaskClass(): string
	{
		return match($this)
		{
			self::TRANSCRIBE => Task\TranscribeCallRecord::class,
			self::OVERVIEW => Task\TranscriptionOverview::class,
			self::SUMMARY => Task\TranscriptionSummary::class,
			self::INSIGHTS => Task\TranscriptionInsights::class,
			self::EVALUATION => Task\MeetingEvaluationTask::class,
		};
	}

	/**
	 * Whether the result of this type is a text one: built by the text engine out of a result the
	 * call already has, and not out of the recording.
	 *
	 * The erasing mode of a restart runs and erases exactly these
	 * {@see \Bitrix\Call\Integration\AI\CallAIService::restartWithPurge()}, and the predicate is
	 * what keeps it away from the media type: the drop of a transcription takes the recording of
	 * the call with it {@see Task\TranscribeCallRecord::drop()}.
	 *
	 * Answered by the category of the engine the task of the type declares, which is the one and
	 * only place that category is stated. A second list of "the text ones" written here would be a
	 * list to forget a new type in; a task cannot be written without declaring its category
	 * {@see Task\AITask::getAIEngineCategory()}, so a type added later lands in one of the
	 * categories by itself.
	 */
	public function isTextual(): bool
	{
		$taskClass = $this->getTaskClass();

		return (new $taskClass())->getAIEngineCategory() === \Bitrix\AI\Engine\Enum\Category::TEXT->value;
	}
}
