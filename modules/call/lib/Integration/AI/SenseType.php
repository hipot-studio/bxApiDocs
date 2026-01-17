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
}