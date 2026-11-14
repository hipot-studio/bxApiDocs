<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Option;

/**
 * Maps the legacy module options onto their feature flags.
 *
 * For the transition release it is also the only reader of those options: the flag defaults,
 * the transfer agent {@see \Bitrix\Call\Update\FeatureFlagTransfer} and the dev pages all ask here,
 * so what counts as "enabled" exists in one place.
 * It sits on the portal read path through every flag default, so it must stay a plain option read.
 *
 * @internal Not an API for other modules.
 */
final class Registry
{
	private const MODULE_ID = 'call';
	private const PORTAL_SITE_ID = '';
	private const CURRENT_SITE = false;

	/**
	 * Options whose flag default is read with the current site taken into account, the way the
	 * facade read them before the transition: on a portal that enabled the feature for one site
	 * alone, the portal value is absent and reading it only would switch the feature off.
	 *
	 * The flags that moved to the mechanism in earlier releases keep the portal-level default they
	 * shipped with: widening it now would change the state of a feature already in production.
	 */
	private const CURRENT_SITE_OPTIONS = [
		'call_reactions_enabled',
	];

	private const READ_CAST = 'cast';
	private const READ_YES = 'yes';
	private const READ_ALLOWLIST = 'allowlist';

	// Option values are stored as strings, and (bool)'N' is true: the Y/N options read through an
	// allowlist accept only these values.
	private const TRUTHY_VALUES = ['Y', '1', 1, true];

	private const OPTIONS = [
		'plain_call_follow_up_enabled' => [PlainCallFollowUpFlag::class, false, self::READ_CAST],
		'plain_call_cloud_recording_enabled' => [PlainCallCloudRecordingFlag::class, false, self::READ_CAST],
		'grid_view_enabled' => [GridViewFlag::class, false, self::READ_CAST],
		'finish_for_all_enabled' => [FinishForAllFlag::class, false, self::READ_CAST],
		'view_toggle_enabled' => [ViewToggleFlag::class, false, self::READ_CAST],
		'large_mobile_call_enabled' => [LargeMobileCallFlag::class, false, self::READ_CAST],
		'push_to_talk_web_enabled' => [WebPushToTalkFlag::class, false, self::READ_CAST],
		'call_disable_camera_new_joined_users_enabled' => [DisableCameraForNewJoinersFlag::class, false, self::READ_CAST],
		'call_stream_quality_enabled' => [StreamQualityFlag::class, false, self::READ_CAST],
		'call_auto_balance_enabled' => [AutoBalanceFlag::class, false, self::READ_CAST],
		'call_sync_preset_enabled' => [SyncPresetFlag::class, false, self::READ_CAST],
		'call_web_background_enabled' => [WebBackgroundFlag::class, 'N', self::READ_YES],
		'call_qos_enabled' => [QosFlag::class, 'N', self::READ_ALLOWLIST],
		'call_qos_enabled_mobile' => [MobileQosFlag::class, 'N', self::READ_ALLOWLIST],
		'call_cloud_record' => [CloudRecordFlag::class, false, self::READ_CAST],
		'call_reactions_enabled' => [ReactionsFlag::class, false, self::READ_ALLOWLIST],
	];

	/**
	 * @return array<string, class-string<AbstractFlag>> Legacy option name => flag class.
	 */
	public static function getFlags(): array
	{
		return array_map(static fn (array $option): string => $option[0], self::OPTIONS);
	}

	/**
	 * The flags the transfer may store, legacy option name => flag class.
	 *
	 * A stored record is portal-wide, and for an option of {@see self::CURRENT_SITE_OPTIONS} no
	 * single record stands for its per-site state: a portal value of 'Y' with 'N' for one site would
	 * be stored as "on" and switch the feature on for that site, where it was off before the update.
	 * Such an option keeps its transition default, which answers per site, until the feature is
	 * switched centrally through the mechanism.
	 *
	 * @return array<string, class-string<AbstractFlag>>
	 */
	public static function getTransferableFlags(): array
	{
		return array_diff_key(self::getFlags(), array_flip(self::CURRENT_SITE_OPTIONS));
	}

	/**
	 * @return class-string<AbstractFlag>|null
	 */
	public static function getFlagClass(string $optionName): ?string
	{
		return self::OPTIONS[$optionName][0] ?? null;
	}

	public static function getOptionName(string $flagClass): ?string
	{
		foreach (self::OPTIONS as $optionName => [$optionFlagClass])
		{
			if ($optionFlagClass === $flagClass)
			{
				return $optionName;
			}
		}

		return null;
	}

	/**
	 * Whether the legacy option is on, read with its current default at the portal level: a stored
	 * flag record is portal-wide, so a value set for one site alone must not become one.
	 */
	public static function isLegacyOptionEnabled(string $optionName): bool
	{
		return self::isOptionEnabled($optionName, self::PORTAL_SITE_ID);
	}

	/**
	 * Whether the legacy option of the flag is on: the transition default of the flag.
	 *
	 * An option of {@see self::CURRENT_SITE_OPTIONS} is read for the current site here, so the
	 * default answers exactly as the facade did before the transition. Nothing is stored by this
	 * read, so the answer stays with the site that has the value.
	 */
	public static function isLegacyOptionEnabledForFlag(string $flagClass): bool
	{
		$optionName = self::getOptionName($flagClass);

		return $optionName !== null && self::isOptionEnabled($optionName, self::getSiteIdFor($optionName));
	}

	/**
	 * @param string|false $siteId The site to read the option for, false standing for the current
	 * one, which falls back to the portal value. The portal level does not fall back to a site.
	 */
	private static function isOptionEnabled(string $optionName, string|false $siteId): bool
	{
		if (!isset(self::OPTIONS[$optionName]))
		{
			return false;
		}

		[, $default] = self::OPTIONS[$optionName];
		$value = Option::get(self::MODULE_ID, $optionName, $default, $siteId);

		return self::isLegacyOptionValueEnabled($optionName, $value);
	}

	/**
	 * @return string|false The site the option is read for, false standing for the current one.
	 */
	private static function getSiteIdFor(string $optionName): string|false
	{
		return in_array($optionName, self::CURRENT_SITE_OPTIONS, true)
			? self::CURRENT_SITE
			: self::PORTAL_SITE_ID;
	}

	/**
	 * Interprets a stored value of the legacy option the way the facade always has.
	 */
	public static function isLegacyOptionValueEnabled(string $optionName, mixed $value): bool
	{
		return match (self::OPTIONS[$optionName][2] ?? null)
		{
			self::READ_CAST => (bool)$value,
			self::READ_YES => $value === 'Y',
			self::READ_ALLOWLIST => in_array($value, self::TRUTHY_VALUES, true),
			default => false,
		};
	}
}
