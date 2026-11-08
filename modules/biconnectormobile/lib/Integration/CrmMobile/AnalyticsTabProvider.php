<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Integration\CrmMobile;

use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnectorMobile\Service\ScopeVisibilityService;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Handler of crmmobile:onGetTabs: contributes the "Analytics" tab descriptor of the zone the section belongs to,
 * when the entry point of that zone is visible - ScopeVisibilityService owns that rule.
 * The mapping "section -> zone" is owned here: crmmobile only publishes the event.
 */
final class AnalyticsTabProvider
{
	private const TAB_ID = 'biconnectormobile::analytics';

	// Runtime jn module id the external tab descriptor points at (slash form, as defined in the bundle).
	private const RUNTIME_MODULE_ID = 'biconnector/dashboard-list';

	private const LOGGER_ID = 'biconnectormobile.tabs';

	public static function onGetTabs(Event $event): EventResult
	{
		$empty = new EventResult(EventResult::SUCCESS, ['tabs' => []]);

		try
		{
			// Nothing else in this request has a reason to load biconnector, and the scope codes below are
			// its constants: without the module the class is not even resolvable. The feature has no scopes
			// then, so there is no tab either.
			if (!Loader::includeModule('biconnector'))
			{
				return $empty;
			}

			// The publisher declares the parameter as ?int, but an event bag carries no types of its own.
			$customSectionId = $event->getParameter('customSectionId');
			$scopeCode = self::resolveScopeCode($customSectionId === null ? null : (int)$customSectionId);
			if ($scopeCode === null)
			{
				return $empty;
			}

			if (!ScopeVisibilityService::isScopeVisible($scopeCode))
			{
				return $empty;
			}

			return new EventResult(EventResult::SUCCESS, ['tabs' => [self::prepareTab($scopeCode)]]);
		}
		catch (\Throwable $exception)
		{
			// The tab is a guest of the CRM panel: our own failure must cost the tab, not the whole panel.
			(new LoggerFactory())->createById(self::LOGGER_ID)?->error(
				'{date}: BIConnectorMobile: the analytics tab of the CRM panel is skipped: {exception}' . PHP_EOL,
				['exception' => $exception],
			);

			return $empty;
		}
	}

	/**
	 * Zone of the section the tabs are built for: the main CRM section owns the "crm" zone, a custom section owns
	 * the zone of its automated solution. Null means there is no zone to show, so there is no tab either.
	 */
	private static function resolveScopeCode(?int $customSectionId): ?string
	{
		if ($customSectionId === null)
		{
			return ScopeService::BIC_SCOPE_CRM;
		}

		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		$solution = Container::getInstance()
			->getAutomatedSolutionManager()
			->getAutomatedSolutionByIntranetCustomSectionId($customSectionId)
		;
		if ($solution === null)
		{
			// A custom section not bound to an automated solution has no zone of its own.
			return null;
		}

		return ScopeService::BIC_SCOPE_AUTOMATED_SOLUTION_PREFIX . (int)$solution['ID'];
	}

	private static function prepareTab(string $scopeCode): array
	{
		return [
			'type' => 'external',
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('BICONNECTORMOBILE_ANALYTICS_TAB_TITLE'),
			'externalRender' => [
				'module' => self::RUNTIME_MODULE_ID,
				'component' => 'DashboardList',
				'params' => [
					'scopeCode' => $scopeCode,
				],
			],
		];
	}
}
