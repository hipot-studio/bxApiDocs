<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Service;

use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnectorMobile\AccessManager;
use Bitrix\BIConnectorMobile\Public\Features\MobileBIFeature;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Mobile\Config\Feature;

/**
 * Answers whether an entry point of the mobile BI feature is visible, one predicate per kind of entry point.
 * The only place the feature flag is read on the server side: controllers, menu and tab providers ask a predicate.
 *
 * The predicates are not interchangeable. isFeatureEnabled() tells the transport refusal by the flag apart from
 * a refusal by rights and from an empty scope, isRootEntryVisible() serves the menu item, isScopeVisible() the
 * scope tabs.
 */
class ScopeVisibilityService
{
	private const LOGGER_ID = 'biconnectormobile.scope_visibility';

	/**
	 * Request-scoped results of isScopeVisible(), keyed by user id and scope code. Deliberately not moved to a
	 * cross-request cache: the query behind it is permission-dependent.
	 *
	 * @var array<string, bool>
	 */
	private static array $scopeVisibility = [];

	public static function isFeatureEnabled(): bool
	{
		return Feature::isEnabled(MobileBIFeature::class);
	}

	/**
	 * The root entry point is the "BI" section of the application menu. It lists dashboards of every scope, so a
	 * non-empty scope is not a condition of it and no query is made here - the same three conditions as on the web.
	 */
	public static function isRootEntryVisible(): bool
	{
		return self::isFeatureEnabled() && AccessManager::canViewDashboards();
	}

	public static function isScopeVisible(string $scopeCode): bool
	{
		$key = (int)CurrentUser::get()->getId() . ':' . $scopeCode;
		if (isset(self::$scopeVisibility[$key]))
		{
			return self::$scopeVisibility[$key];
		}

		$result = false;
		if (self::isRootEntryVisible())
		{
			try
			{
				$result = static::hasAccessibleDashboards($scopeCode);
			}
			catch (\Throwable $exception)
			{
				// Fail-closed: a failed lookup hides the scope entry point. The root one stays visible on purpose,
				// otherwise a single failed query takes away the only way into the feature.
				$result = false;

				// A silent fail-closed would hide every scope entry point at once, leaving no trace of why.
				(new LoggerFactory())->createById(self::LOGGER_ID)?->error(
					'{date}: BIConnectorMobile: the dashboard lookup of the scope {scopeCode} failed, '
					. 'the entry point is hidden: {exception}' . PHP_EOL,
					[
						'scopeCode' => $scopeCode,
						'exception' => $exception,
					],
				);
			}
		}

		self::$scopeVisibility[$key] = $result;

		return $result;
	}

	/**
	 * Single point where the dashboard list of a scope is queried, kept substitutable for tests.
	 * isDashboardsExist() is not an option here - it ignores rights.
	 */
	protected static function hasAccessibleDashboards(string $scopeCode): bool
	{
		return ScopeService::getInstance()->hasAccessibleDashboards($scopeCode);
	}
}
