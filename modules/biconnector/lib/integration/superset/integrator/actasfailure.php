<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Error;

/**
 * A read on behalf of a person that Superset could not run as that person. Neither case is transient,
 * so a caller must not be told that the data is still warming up or to try again.
 */
final class ActAsFailure
{
	public const NOT_RUN_AS_PERSON_MESSAGE = 'BI data cannot be read on behalf of the current user right now. '
		. 'Do not retry; tell the user that BI access for their account needs to be checked by the administrator.'
	;
	public const OUTDATED_INSTANCE_MESSAGE = 'BI Builder on this portal is older than reading on behalf of the user '
		. 'requires. Do not retry; tell the user that the administrator needs to update BI Builder.'
	;
	public const FILTER_ACCESS_DENIED_MESSAGE = 'The values of this filter are closed for the current user '
		. 'by their access rights. Do not retry and do not guess the values; tell the user they have no access to them.'
	;
	public const DASHBOARD_ACCESS_DENIED_MESSAGE = 'The data of this dashboard is closed in BI Builder '
		. 'for the current user. Do not retry and do not guess its data; tell the user they have no access to it '
		. 'and that the administrator can check their BI access.'
	;
	public const CHARTS_ACCESS_DENIED_MESSAGE = 'The requested charts are closed for the current user by their access '
		. 'rights. Do not retry and do not guess their data; tell the user they have no access to these charts.'
	;
	public const TABLE_ACCESS_DENIED_MESSAGE = 'The data this request reads is closed for the current user '
		. 'by their access rights. Do not retry and do not work around it with other tables; '
		. 'tell the user they have no access to this data.'
	;

	/**
	 * Codes of the act-as contract with Superset: the request could not be run as the person.
	 */
	private const ACT_AS_CODES = [
		'act_as_unsupported',
		'act_as_invalid_login',
		'BX_ACT_AS_DENIED',
		'BX_ACT_AS_INVALID',
	];
	// A box instance below the build that runs reads as a person, see Middleware\SupersetVersionCheck.
	private const OUTDATED_CODE = 'VERSION_NOT_SUPPORTED';
	// Mark of a chart of the overview, a filter preview or a request closed by the table rights of the person;
	// its tables are not named.
	private const CHART_ACCESS_DENIED_CODE = 'table_access_denied';

	/**
	 * A chart of the dashboard overview that the person may not read: it comes without data and without the
	 * tables it was refused on, so the caller answers by the rest of the dashboard.
	 */
	public static function isChartAccessDenied(array $chart): bool
	{
		return ($chart['error_code'] ?? null) === self::CHART_ACCESS_DENIED_CODE;
	}

	/**
	 * A filter preview built on a dataset the person may not read: it comes with empty values and the same mark,
	 * so the caller must not take it for a filter without values.
	 */
	public static function isFilterAccessDenied(array $filterValues): bool
	{
		return ($filterValues['error_code'] ?? null) === self::CHART_ACCESS_DENIED_CODE;
	}

	/**
	 * A read of a dashboard as the person that Superset refused or did not find. The portal lets the person
	 * to the dashboard before the read, so this is about the Superset access of the person, not a dashboard
	 * that is still warming up. Only a read made as the person says so: the caller knows whether it was.
	 */
	public static function isDashboardRefused(IntegratorResponse $response): bool
	{
		return in_array(
			$response->getStatus(),
			[IntegratorResponse::STATUS_NO_ACCESS, IntegratorResponse::STATUS_NOT_FOUND],
			true,
		);
	}

	/**
	 * @param Error[] $errors
	 */
	public static function isOutdatedInstance(array $errors): bool
	{
		return self::hasCode($errors, [self::OUTDATED_CODE]);
	}

	/**
	 * @param Error[] $errors
	 */
	public static function isNotRunAsPerson(array $errors): bool
	{
		return self::hasCode($errors, self::ACT_AS_CODES);
	}

	/**
	 * A request run as the person that Superset refused by their table rights, e.g. a dataset over a closed table.
	 * The refusal does not name the tables.
	 *
	 * @param Error[] $errors
	 */
	public static function isTableAccessDenied(array $errors): bool
	{
		return self::hasCode($errors, [self::CHART_ACCESS_DENIED_CODE]);
	}

	/**
	 * @param Error[] $errors
	 * @return string|null What to tell the caller, or null when the errors say nothing about act-as.
	 */
	public static function findMessage(array $errors): ?string
	{
		if (self::isOutdatedInstance($errors))
		{
			return self::OUTDATED_INSTANCE_MESSAGE;
		}
		if (self::isTableAccessDenied($errors))
		{
			return self::TABLE_ACCESS_DENIED_MESSAGE;
		}

		return self::isNotRunAsPerson($errors) ? self::NOT_RUN_AS_PERSON_MESSAGE : null;
	}

	/**
	 * @param Error[] $errors
	 * @param string[] $codes
	 */
	private static function hasCode(array $errors, array $codes): bool
	{
		foreach ($errors as $error)
		{
			if (in_array((string)$error->getCode(), $codes, true))
			{
				return true;
			}
		}

		return false;
	}
}
