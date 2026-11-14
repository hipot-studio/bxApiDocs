<?php

namespace Bitrix\Mobile\Internal\Onboarding\Config;

use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * Values of one final push (meetup, implementation help) read from the `finals`
 * section of the campaign document: the workday offset from its own anchor, the send
 * condition, both analytics codes and the card of the section the push aims at.
 *
 * The target travels with the push because the client cannot know which card the campaign
 * had in mind: a tap that only brings the section up leaves the user to find it themselves.
 * A final that names no card is the ordinary case while the card does not exist yet.
 *
 * The click code travels with the push for the same reason: it is authored per final in the
 * document, and the device has nothing to assemble it from.
 *
 * Parsing fails closed - a node without a usable offset, or one asking for a
 * condition the code does not implement, yields null. An incomplete document then
 * stops the finals instead of guessing a send date or silently dropping a gate.
 */
final readonly class FinalPushConfig
{
	private function __construct(
		public int $offsetWorkdays,
		public ?FinalCondition $condition,
		public ?string $analyticsSendEvent,
		public ?string $analyticsClickEvent,
		public ?string $deeplinkTarget,
		public bool $highlightTarget,
	)
	{}

	/**
	 * @param array<string, mixed> $finals the `finals` section of the campaign document
	 */
	public static function fromFinals(array $finals, PushType $pushType): ?self
	{
		$finalKey = $pushType->getFinalKey();
		$node = $finalKey === null ? null : ($finals[$finalKey] ?? null);
		if (!is_array($node))
		{
			return null;
		}

		$offset = $node[self::getOffsetKey($pushType) ?? ''] ?? null;
		if (!is_numeric($offset) || (int)$offset <= 0)
		{
			return null;
		}

		$rawCondition = $node['condition'] ?? null;
		$condition = is_string($rawCondition) ? FinalCondition::tryFrom($rawCondition) : null;
		if ($rawCondition !== null && $condition === null)
		{
			return null;
		}

		$analyticsSendEvent = $node['analytics']['send'] ?? null;
		$analyticsClickEvent = $node['analytics']['click'] ?? null;

		$deeplinkTarget = $node['deeplink']['menuItemId'] ?? null;
		$deeplinkTarget = is_string($deeplinkTarget) && $deeplinkTarget !== '' ? $deeplinkTarget : null;

		return new self(
			min((int)$offset, ScheduleConfig::MAX_OFFSET_DAYS),
			$condition,
			is_string($analyticsSendEvent) && $analyticsSendEvent !== '' ? $analyticsSendEvent : null,
			is_string($analyticsClickEvent) && $analyticsClickEvent !== '' ? $analyticsClickEvent : null,
			$deeplinkTarget,
			// the highlight is a request about a card, so it goes away with the card it asked for
			$deeplinkTarget !== null && ($node['deeplink']['highlight'] ?? false) === true,
		);
	}

	/**
	 * Anchor of every final: the meetup counts from the last push of the activation
	 * series, the implementation help from the meetup itself. Keyed on the push type
	 * itself, like PushType::getFinalKey(), so the finals are named in one place.
	 */
	private static function getOffsetKey(PushType $pushType): ?string
	{
		return match ($pushType) {
			PushType::MEETUP => 'offsetWorkdaysAfterLastSeries',
			PushType::IMPLEMENTATION_HELP => 'offsetWorkdaysAfterMeetup',
			default => null,
		};
	}
}
