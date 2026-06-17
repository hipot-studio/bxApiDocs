<?php

namespace Bitrix\HumanResources\Type;

use Bitrix\HumanResources\Internals\Trait\ValuesTrait;

/**
 * Types for NodeSettingsTable to determine which setting is stored in a row
 */
enum NodeSettingsType: string
{
	public const BOOLEAN_TRUE = 'Y';
	public const BOOLEAN_FALSE = 'N';

	case BusinessProcAuthority = 'BUSINESS_PROC_AUTHORITY';
	case ReportsAuthority = 'REPORTS_AUTHORITY';
	case TeamReportExceptions = 'TEAM_REPORT_EXCEPTIONS';
	case AutoCheckin = 'AUTO_CHECKIN';
	case WelcomeBox = 'WELCOME_BOX';
	case AiReports = 'AI_REPORTS';
	case DayStartCheckinRequired = 'DAY_START_CHECKIN_REQUIRED';

	public function isAuthorityType(): bool
	{
		return in_array($this, self::getCasesWithAuthorityTypeValue(), true);
	}

	public function isBooleanType(): bool
	{
		return in_array($this, self::getCasesWithBooleanValue(), true);
	}

	public function isUserIdsType(): bool
	{
		return in_array($this, self::getCasesWithUserIdsValue(), true);
	}

	/**
	 * Types that must be written only via Public\Service\NodeSettingsService
	 * and not via SaveNodeSettingsCommand.
	 */
	public function isPublicApiOnly(): bool
	{
		return in_array($this, self::getCasesForPublicApiOnly(), true);
	}

	/**
	 * Get node types which values should be validated with NodeSettingsAuthorityType values
	 *
	 * @return NodeSettingsType[]
	 */
	public static function getCasesWithAuthorityTypeValue()
	{
		return [
			self::BusinessProcAuthority,
			self::ReportsAuthority,
		];
	}

	/**
	 * @return NodeSettingsType[]
	 */
	public static function getCasesWithBooleanValue(): array
	{
		return [self::AutoCheckin, self::WelcomeBox, self::AiReports, self::DayStartCheckinRequired];
	}

	public static function getCasesWithUserIdsValue(): array
	{
		return [self::TeamReportExceptions];
	}

	/**
	 * Settings inherited from the immediate parent department when a new department is created.
	 * If the parent has a value stored — the child copies it; otherwise the child stays unset.
	 *
	 * Inclusion in this list is a conscious choice per setting — not every boolean setting
	 * is inheritable, and a non-boolean setting could be inheritable in the future.
	 *
	 * @return NodeSettingsType[]
	 */
	public static function getCasesInheritedFromParent(): array
	{
		return [
			self::AutoCheckin,
			self::WelcomeBox,
			self::AiReports,
			self::DayStartCheckinRequired,
		];
	}

	/**
	 * @return NodeSettingsType[]
	 */
	public static function getCasesForPublicApiOnly(): array
	{
		return [self::AutoCheckin, self::WelcomeBox, self::AiReports, self::DayStartCheckinRequired];
	}

	public static function booleanToString(bool $value): string
	{
		return $value ? self::BOOLEAN_TRUE : self::BOOLEAN_FALSE;
	}

	public static function booleanFromString(?string $value): ?bool
	{
		return match ($value) {
			self::BOOLEAN_TRUE => true,
			self::BOOLEAN_FALSE => false,
			default => null,
		};
	}

	use ValuesTrait;
}
