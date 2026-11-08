<?php

declare(strict_types=1);

namespace Bitrix\AI\Config;

/**
 * Exact names of module options that are not feature flags.
 *
 * Selection for release analytics goes by exclusions, so every option of the module is watched
 * unless its name is listed here. Only names that can actually hold `'Y'` belong here: addresses,
 * secrets, limits, numbers, structures, dates and traces are cut off by the `'Y'` rule at runtime.
 *
 * Classification goes by the behaviour of the option, never by the shape of its name - a rule by
 * name shape is what broke the developer flag page. An option shipped enabled (default `'Y'`,
 * the option works as a switch) stays out; an option with default `'N'` stays out when its role
 * is an engineering mode, debugging, an installation step or a service marker, and is watched
 * when its role is to open functionality.
 *
 * Taking a name out of this list requires seeding that name in the updater of the same release.
 * A name that leaves the list has no marker, so on a portal where the option has held `'Y'` for
 * ages the first rewrite would report it as released today - the very defect the seeding exists
 * to prevent. Adding a name here needs nothing: an excluded option is never reported.
 *
 * Watched release flags of this module today: `tariff_limit_policy_enabled`,
 * `bitrixgpt_enabled_west`, `bitrixgpt_vl_enabled`.
 */
final class FeatureFlagOptions
{
	private const EXCLUDED_OPTIONS = [
		// Limit checking mode, not a release.
		'check_limits',
		// Engineering queue modes.
		'force_queue',
		'queue_mode',
		// Provider engine switch, not a product feature.
		'ai_engine_kandinsky_enable',
		// Emergency shutdown of the business process activity.
		'bp_ai_processing_activity_disabled',
		// Installation process marker.
		'install_started',
		// Debug logging modes.
		'write_history_always',
		'write_history_request',
		'write_errors',
		// Agreement accepted by an administrator: the most frequently written 'Y' option of the
		// module, and without this entry it would report a false flag on almost every portal.
		'bitrixgpt_agreement_status',
		// Box/cloud environment switch.
		'use_bitrix24',
		// Historic engine defaults.
		'bitrixgpt_enabled',
		'bitrixaudio_enabled',
	];

	/**
	 * @return string[]
	 */
	public static function getExcludedOptions(): array
	{
		return self::EXCLUDED_OPTIONS;
	}
}
