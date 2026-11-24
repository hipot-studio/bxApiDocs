<?php

namespace Bitrix\Mobile\Internal\Onboarding\Config;

/**
 * Values of one step of the `steps` section of the campaign document: the attempt
 * limit of the step, the gate condition it is subordinated to, the send- and click-analytics
 * codes and the deeplink declared for it.
 *
 * Both analytics codes are read here because they are the two halves of one funnel: the send
 * is reported by the server, the click by the device, and a click reported under a code the
 * client invented cannot be joined to the send it came from.
 *
 * The attempt limit is the single default of the pipeline: a document that declares no
 * usable maxAttempts nudges the step DEFAULT_MAX_ATTEMPTS times - once - rather than
 * without a bound. Both the send decision and the content lookup read it here, so they
 * cannot drift apart.
 *
 * The gate is a token of GateCondition, turned into conditions by the rules of the campaign
 * (CampaignRuleSet). An unknown token reads as null, and the rules then apply their own
 * default rather than leaving the step ungated.
 *
 * The deeplink is carried for tooling and diagnostics only: the action a push actually
 * opens stays in code (ActionTypeProvider), so the document cannot introduce one.
 */
final readonly class StepConfig
{
	public const DEFAULT_MAX_ATTEMPTS = 1;

	private function __construct(
		public int $maxAttempts,
		public ?string $analyticsSendEvent,
		public ?string $analyticsClickEvent,
		public ?string $deeplinkAction,
		public ?GateCondition $gate = null,
	)
	{}

	/**
	 * @param array<string, mixed> $node a single node of the `steps` section
	 */
	public static function fromStepNode(array $node): self
	{
		$maxAttempts = $node['maxAttempts'] ?? null;
		$analyticsSendEvent = $node['analytics']['send'] ?? null;
		$analyticsClickEvent = $node['analytics']['click'] ?? null;
		$deeplinkAction = $node['deeplink']['action'] ?? null;
		$gate = $node['gate'] ?? null;

		return new self(
			self::parseMaxAttempts($maxAttempts),
			is_string($analyticsSendEvent) && $analyticsSendEvent !== '' ? $analyticsSendEvent : null,
			is_string($analyticsClickEvent) && $analyticsClickEvent !== '' ? $analyticsClickEvent : null,
			is_string($deeplinkAction) && $deeplinkAction !== '' ? $deeplinkAction : null,
			is_string($gate) ? GateCondition::tryFrom($gate) : null,
		);
	}

	/**
	 * The attempt count is clamped the way the day offsets are, and for the same reason: the
	 * value comes from a document the engine does not own, and every attempt of it is walked
	 * one by one for every industry bucket of every step, both when the document is accepted
	 * and on every read of the option it was saved into. The ceiling is not a stricter
	 * campaign - it bounds that walk.
	 *
	 * It is not a bound on the series. The series has no fixed number of send days: it lasts
	 * as long as the user keeps completing steps, and it is these counters that end it.
	 */
	private static function parseMaxAttempts(mixed $maxAttempts): int
	{
		if (!is_numeric($maxAttempts) || (int)$maxAttempts <= 0)
		{
			return self::DEFAULT_MAX_ATTEMPTS;
		}

		return min((int)$maxAttempts, ScheduleConfig::MAX_SEND_DAYS);
	}
}
