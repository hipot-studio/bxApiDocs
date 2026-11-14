<?php

namespace Bitrix\Crm\Integration\AI\Enum;

use Bitrix\AI;

enum SpeakerSource: string
{
	case ChannelPerSpeaker = 'channel_per_speaker';
	case DiarizationPerTrackKnownSpeakers = 'diarization_per_track_known_speakers';
	case DiarizationMonoKnownSpeakers = 'diarization_mono_known_speakers';
	case NotApplied = 'not_applied';

	public static function fromResult(AI\Result $result): ?self
	{
		$source = AI\Engine\Enum\SpeakerSource::fromResult($result);

		return $source === null ? null : self::tryFrom($source->value);
	}
}
