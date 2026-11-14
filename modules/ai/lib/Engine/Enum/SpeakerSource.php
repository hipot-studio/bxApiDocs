<?php

declare(strict_types=1);

namespace Bitrix\AI\Engine\Enum;

use Bitrix\AI\Result;

/**
 * How the speakers of a call transcript were told apart.
 *
 * The values are taken verbatim from the transcription service dictionary, which it shares with
 * every consumer and not with CRM alone. Splitting by channels is more trustworthy than a model
 * guess, so those cases are kept apart instead of collapsing into one "diarized" value. The service
 * does not report the key yet - the neighbouring task that makes it do so is not released.
 */
enum SpeakerSource: string
{
	/** One track per speaker - a stereo call or a video call where every participant came on a channel of their own. */
	case ChannelPerSpeaker = 'channel_per_speaker';

	/** A multitrack recording where a model additionally told apart the speakers inside a track. */
	case DiarizationPerTrackKnownSpeakers = 'diarization_per_track_known_speakers';

	/** A mono recording where the speakers were told apart by a model alone. */
	case DiarizationMonoKnownSpeakers = 'diarization_mono_known_speakers';

	/** Speakers were not marked up at all - a value in its own right, not an absent answer. */
	case NotApplied = 'not_applied';

	/** The key the provider reports the value under in the raw answer. */
	private const RAW_KEY = 'speakerSource';

	/**
	 * Reads the speaker source out of an engine answer.
	 *
	 * Returns null whenever the answer carries no usable value: the raw answer is whatever the
	 * provider sent and is not even an array when a transcription has merely been queued, and an
	 * unrecognized value is a provider value this enum does not cover. Neither case is an error
	 * here, so nothing is thrown - the caller decides what an unknown source means.
	 */
	public static function fromResult(Result $result): ?self
	{
		$raw = $result->getRawData();
		if (!is_array($raw))
		{
			return null;
		}

		$value = $raw[self::RAW_KEY] ?? null;
		if (!is_string($value))
		{
			return null;
		}

		return self::tryFrom($value);
	}
}
