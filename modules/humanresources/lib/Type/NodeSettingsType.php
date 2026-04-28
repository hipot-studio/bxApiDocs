<?php

namespace Bitrix\HumanResources\Type;

use Bitrix\HumanResources\Internals\Trait\ValuesTrait;

/**
 * Types for NodeSettingsTable to determine which setting is stored in a row
 */
enum NodeSettingsType: string
{
	case BusinessProcAuthority = 'BUSINESS_PROC_AUTHORITY';
	case ReportsAuthority = 'REPORTS_AUTHORITY';
	case TeamReportExceptions = 'TEAM_REPORT_EXCEPTIONS';

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

	public static function getCasesWithBooleanValue(): array
	{
		return []; // no such cases for now
	}

	public static function getCasesWithUserIdsValue(): array
	{
		return [self::TeamReportExceptions];
	}

	use ValuesTrait;
}
