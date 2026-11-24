<?php

namespace Bitrix\Mobile\Internal\Onboarding\Config;

use Bitrix\Mobile\Internal\Onboarding\Activation\ActivationPath;
use Bitrix\Mobile\Internal\Onboarding\Activation\ActivationStep;
use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * The configuration seam of the activation series: the rules of the push channel
 * (CampaignRuleSet) and the push pipeline know only this interface, never the concrete source
 * (bundled code default, cached JSON, remote admin service). Swapping the source
 * behind it changes neither the rules nor the domain model.
 *
 * Backing document sections: meta, schedule, steps, paths, industries, content,
 * finals, analytics.
 *
 * The pipeline itself runs on the typed part of the contract: getPaths(),
 * getScheduleConfig(), findStepByPushType(), getGateCondition(), getContent(),
 * getFinalContent(), getFinals() and getAnalytics(). The raw-section methods -
 * getMeta(), getStepConfig(), getSchedule() - exist for tools and diagnostics (the dev
 * harness, config tests) and carry no default: they answer exactly what the document holds.
 *
 * Two keys of the document are addressed to its author and read by nobody here, because
 * what they describe is declared in code: `steps[].checker` (the check of a step is the
 * condition CampaignRuleSet declares for its id) and `finals[].sendOnce` (a final is sent once by
 * construction, see OnboardingService). Every other key is read in exactly one place and
 * explained there - StepConfig, FinalPushConfig, ScheduleConfig and the acceptance criteria
 * of JsonCampaignConfigProvider.
 */
interface CampaignConfigProvider
{
	/**
	 * Every configured path, keyed by path code. Which of them a user walks is decided by the
	 * rules of the campaign alone (CampaignRuleSet), where the "default" path is only the
	 * fallback: its own tools and priority are never weighed.
	 *
	 * @return array<string, ActivationPath>
	 */
	public function getPaths(): array;

	/**
	 * The `meta` section (version, region, enabled, source) - identifies the document
	 * the engine is currently running on.
	 *
	 * @return array<string, mixed>
	 */
	public function getMeta(): array;

	/**
	 * Raw step node from the JSON `steps` section (checker/gate/pushType/
	 * maxAttempts/deeplink/analytics), or null when the id is unknown. For tools and
	 * diagnostics - the pipeline reads a step through findStepByPushType().
	 *
	 * @return array<string, mixed>|null
	 */
	public function getStepConfig(string $stepId): ?array;

	/**
	 * Values of the step targeting the given push type (`steps[].pushType`), or null
	 * when no step targets it. Lets the push pipeline read the attempt limit and the
	 * analytics code of a resolved push type, which is all the
	 * activation rule chooses. Mapping the type onto its document token is the job
	 * of the configuration layer, not of the caller.
	 */
	public function findStepByPushType(PushType $pushType): ?StepConfig;

	/**
	 * Gate condition declared for the step (`steps[].gate`), or null when the step
	 * declares none or names a condition unknown to the code. Selecting the condition is
	 * all the document may do: the gates themselves are declared in code, as conditions
	 * of the rules of the campaign (CampaignRuleSet).
	 */
	public function getGateCondition(ActivationStep $step): ?GateCondition;

	/**
	 * Localized push content leaf {title, body} for a push type, resolving the
	 * industry (its own bucket, then its `industryAliases` target, then `defaultIndustry`,
	 * then `_default`) and the attempt key (falls back to `*`). Null when the push type
	 * has no content. See ACTION-MAP and INDUSTRY-MAP; used by PushContentProvider.
	 * `$pushType` is the content-section key (task/crm/invite/message/web).
	 *
	 * @return array{title: string, body: string}|null
	 */
	public function getContent(string $pushType, ?string $industry = null, string $attemptKey = '*'): ?array;

	/**
	 * Localized content leaf {title, body} of a final push, addressed by its key in
	 * the `finals` section (meetup/implementation_help) and resolving the industry the
	 * same way as getContent(). The finals author no attempt variations, so there is no
	 * attempt key. Null when the final has no content.
	 *
	 * @return array{title: string, body: string}|null
	 */
	public function getFinalContent(string $final, ?string $industry = null): ?array;

	/**
	 * The `schedule` section (anchor, maxPortalAgeDays, sendDayOffsets, sendWindow, ...)
	 * as authored. For tools and diagnostics - the pipeline runs on getScheduleConfig().
	 *
	 * @return array<string, mixed>
	 */
	public function getSchedule(): array;

	/**
	 * Values of the `schedule` section the pipeline runs on: send window, portal age
	 * gate, series day offsets and the calendar rules applied to them, parsed
	 * fail-closed and with the offsets clamped.
	 */
	public function getScheduleConfig(): ScheduleConfig;

	/**
	 * The `finals` section (meetup, implementation_help) - offsets, deeplinks,
	 * analytics and content. Consumed by the final pushes of the series.
	 *
	 * @return array<string, mixed>
	 */
	public function getFinals(): array;

	/**
	 * The `analytics` section, including the `series` token substituted into the
	 * `{series}` placeholder of per-step/final analytics codes.
	 *
	 * @return array<string, mixed>
	 */
	public function getAnalytics(): array;

	/**
	 * The manifest the portal runs on right now, named for the log: its format version and the
	 * revision of the campaign it publishes. Diagnostics only - two manifests can be live on a
	 * portal (the published one and the bundled fallback it degrades to), and nothing else in
	 * the log of a pass says which of them is in charge.
	 */
	public function describeAppliedManifest(): string;
}
