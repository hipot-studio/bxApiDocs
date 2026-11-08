<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Integration\Mobile;

use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnectorMobile\Service\ScopeVisibilityService;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\MobileApp\Janative\Manager;

Loc::loadMessages(__FILE__);

final class ProfileTabProvider
{
	private const TAB_ID = 'biconnector_analytics';
	private const COMPONENT_NAME = 'biconnector:biconnector.dashboard.list';

	private const LOGGER_ID = 'biconnectormobile.tabs';

	public static function onProfileTabsCollect(Event $event): EventResult
	{
		$empty = new EventResult(EventResult::SUCCESS, ['tabs' => []]);

		try
		{
			// Nothing else in this request has a reason to load biconnector, and the scope code below is its
			// constant: without the module the class is not even resolvable. The feature has no scopes then,
			// so there is no tab either.
			if (!Loader::includeModule('biconnector'))
			{
				return $empty;
			}

			if (!ScopeVisibilityService::isScopeVisible(ScopeService::BIC_SCOPE_PROFILE))
			{
				return $empty;
			}

			$tab = self::prepareTab();
			if ($tab === null)
			{
				return $empty;
			}

			return new EventResult(EventResult::SUCCESS, ['tabs' => [$tab]]);
		}
		catch (\Throwable $exception)
		{
			// The tab is a guest of the profile: our own failure must cost the tab, not the whole profile.
			(new LoggerFactory())->createById(self::LOGGER_ID)?->error(
				'{date}: BIConnectorMobile: the analytics tab of the profile is skipped: {exception}' . PHP_EOL,
				['exception' => $exception],
			);

			return $empty;
		}
	}

	/**
	 * Null means the tab cannot be built: no tab at all is better than a tab with nothing behind it.
	 */
	private static function prepareTab(): ?array
	{
		if (!Loader::includeModule('mobileapp'))
		{
			return null;
		}

		$scriptPath = Manager::getComponentPath(self::COMPONENT_NAME);
		if ($scriptPath === '')
		{
			// The component is missing from the registry, so the tab would open an empty screen.
			return null;
		}

		$params = [
			'SCOPE_CODE' => ScopeService::BIC_SCOPE_PROFILE,
		];

		return [
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('BICONNECTORMOBILE_PROFILE_ANALYTICS_TAB_TITLE'),
			'params' => $params,
			'componentName' => self::COMPONENT_NAME,
			'component' => [
				'name' => 'JSStackComponent',
				'componentCode' => self::TAB_ID,
				'scriptPath' => $scriptPath,
				'rootWidget' => [
					'name' => 'layout',
					'settings' => [
						'objectName' => 'layout',
					],
				],
				'params' => $params,
			],
		];
	}
}
