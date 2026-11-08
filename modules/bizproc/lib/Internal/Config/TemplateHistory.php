<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Bizproc\Public\Feature\Template\TemplateVersionHistoryFlag;
use Bitrix\Main\Config\Option;

/**
 * The only place where the template version history options are read: recording, rotation and the
 * history surfaces are all derived from these getters.
 */
class TemplateHistory
{
	private const MODULE_ID = 'bizproc';

	private const VERSION_LIMIT_OPTION = 'template_version_limit';
	private const MERGE_WINDOW_OPTION = 'template_change_merge_window';
	private const RETENTION_DAYS_OPTION = 'template_change_retention_days';

	private const DEFAULT_VERSION_LIMIT = 10;
	private const DEFAULT_MERGE_WINDOW = 1800;
	private const DEFAULT_RETENTION_DAYS = 90;

	public static function isEnabled(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(TemplateVersionHistoryFlag::class);
	}

	/**
	 * How many published versions keep their body; the rest stay in the journal as events only.
	 */
	public static function getVersionLimit(): int
	{
		return self::getPositiveInt(self::VERSION_LIMIT_OPTION, self::DEFAULT_VERSION_LIMIT);
	}

	/**
	 * Seconds within which consecutive draft saves of the same author are merged into one session.
	 */
	public static function getMergeWindow(): int
	{
		return self::getPositiveInt(self::MERGE_WINDOW_OPTION, self::DEFAULT_MERGE_WINDOW);
	}

	/**
	 * Days a change event stays in the journal; the same value bounds both the shown history and the
	 * cleanup agent.
	 */
	public static function getRetentionDays(): int
	{
		return self::getPositiveInt(self::RETENTION_DAYS_OPTION, self::DEFAULT_RETENTION_DAYS);
	}

	private static function getPositiveInt(string $option, int $default): int
	{
		$stored = Option::get(self::MODULE_ID, $option, (string)$default);
		if (!is_numeric($stored))
		{
			return $default;
		}

		$value = (int)$stored;

		return $value > 0 ? $value : $default;
	}
}
