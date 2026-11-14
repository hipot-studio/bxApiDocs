<?php

declare(strict_types=1);

namespace Bitrix\AI\Engine\Dto;

use Bitrix\AI\Result;

/**
 * One utterance of a call transcript: what was said, when, and by whom.
 *
 * The transcription service sends the whole transcript twice over - as one text glued with line
 * breaks, and as this list of segments. The text is the part every consumer already relies on, the
 * segments are the markup on top of it, so losing the markup never costs the transcript itself.
 *
 * The property names are the ones this codebase speaks, not the ones the provider sends: the wire
 * keys live in the private constants below and stay in this file alone.
 */
final readonly class TranscriptSegment
{
	/** The key the provider reports the segment list under in the raw answer. */
	private const RAW_KEY = 'segments';

	/** The keys a single segment is made of. */
	private const KEY_START_TIME = 'start_time_formatted';
	private const KEY_END_TIME = 'end_time_formatted';
	private const KEY_TEXT = 'text';
	private const KEY_SPEAKER = 'speaker';
	private const KEY_TRACK_ID = 'source_id';

	/**
	 * @param string $startTime Offset from the start of the recording, MM:SS - the service formats it,
	 *                          it is not a number.
	 * @param string $endTime Offset the utterance ends at, in the same format.
	 * @param string $text What was said.
	 * @param string|null $speaker Display name of the speaker, null when the provider told nobody apart.
	 * @param string|null $trackId Recording track the utterance came from, null when the provider
	 *                             reports no tracks.
	 */
	public function __construct(
		public string $startTime,
		public string $endTime,
		public string $text,
		public ?string $speaker,
		public ?string $trackId,
	)
	{
	}

	/**
	 * Reads the list of transcript segments out of an engine answer.
	 *
	 * Returns null whenever the answer carries no usable list: the raw answer is whatever the provider
	 * sent and is not even an array when a transcription has merely been queued, the key can be absent
	 * on a service that does not report segments yet, and the value under it can be anything at all.
	 * None of that is an error here, so nothing is thrown - the caller decides what an unknown markup
	 * means.
	 *
	 * An empty list is not null. An absent key means "no segments were sent", an empty array means
	 * "there are no segments, and that is known" - the same distinction the speaker source draws
	 * between not_applied and an absent answer, and the caller is entitled to tell the two apart.
	 *
	 * A single malformed element voids the whole list rather than being repaired or skipped. What goes
	 * out is either the entire markup or none of it, because a partial one is worse than none: it looks
	 * whole and gets believed, and a silently dropped utterance is invisible to whoever reads it. The
	 * transcript survives either way - it travels as its own field, and it is not what this factory
	 * builds.
	 *
	 * @return list<self>|null
	 */
	public static function listFromResult(Result $result): ?array
	{
		$raw = $result->getRawData();
		if (!is_array($raw))
		{
			return null;
		}

		$rawSegments = $raw[self::RAW_KEY] ?? null;
		if (!is_array($rawSegments) || !array_is_list($rawSegments))
		{
			return null;
		}

		$segments = [];
		foreach ($rawSegments as $rawSegment)
		{
			$segment = self::fromRawSegment($rawSegment);
			if ($segment === null)
			{
				return null;
			}

			$segments[] = $segment;
		}

		return $segments;
	}

	/**
	 * Builds a single segment, or answers null when the element does not hold the agreed shape.
	 *
	 * The speaker and the track are optional by agreement with the service: it sends them as nulls when
	 * the provider knows neither, and that is a complete answer, not a broken element.
	 */
	private static function fromRawSegment(mixed $rawSegment): ?self
	{
		if (!is_array($rawSegment))
		{
			return null;
		}

		$startTime = $rawSegment[self::KEY_START_TIME] ?? null;
		$endTime = $rawSegment[self::KEY_END_TIME] ?? null;
		$text = $rawSegment[self::KEY_TEXT] ?? null;
		if (!is_string($startTime) || !is_string($endTime) || !is_string($text))
		{
			return null;
		}

		$speaker = $rawSegment[self::KEY_SPEAKER] ?? null;
		$trackId = $rawSegment[self::KEY_TRACK_ID] ?? null;
		if (($speaker !== null && !is_string($speaker)) || ($trackId !== null && !is_string($trackId)))
		{
			return null;
		}

		return new self($startTime, $endTime, $text, $speaker, $trackId);
	}
}
