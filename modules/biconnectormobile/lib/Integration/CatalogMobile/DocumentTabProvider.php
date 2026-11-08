<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Integration\CatalogMobile;

use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnectorMobile\Service\ScopeVisibilityService;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Handler of catalogmobile:onGetDocumentTabs: contributes the "Analytics" tab descriptor of the store zone to the
 * document panel, when the entry point of that zone is visible - ScopeVisibilityService owns that rule.
 * The panel of documents belongs to the store zone as a whole, so nothing of the event context is needed here.
 */
final class DocumentTabProvider
{
	private const TAB_ID = 'biconnectormobile::analytics';

	// Runtime jn module id the external tab descriptor points at (slash form, as defined in the bundle).
	private const RUNTIME_MODULE_ID = 'biconnector/dashboard-list';

	private const LOGGER_ID = 'biconnectormobile.tabs';

	public static function onGetDocumentTabs(Event $event): EventResult
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

			if (!ScopeVisibilityService::isScopeVisible(ScopeService::BIC_SCOPE_STORE))
			{
				return $empty;
			}

			return new EventResult(EventResult::SUCCESS, ['tabs' => [self::prepareTab()]]);
		}
		catch (\Throwable $exception)
		{
			// The tab is a guest of the document panel: our own failure must cost the tab, not the whole panel.
			(new LoggerFactory())->createById(self::LOGGER_ID)?->error(
				'{date}: BIConnectorMobile: the analytics tab of the document panel is skipped: {exception}' . PHP_EOL,
				['exception' => $exception],
			);

			return $empty;
		}
	}

	private static function prepareTab(): array
	{
		return [
			'type' => 'external',
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('BICONNECTORMOBILE_STORE_ANALYTICS_TAB_TITLE'),
			'externalRender' => [
				'module' => self::RUNTIME_MODULE_ID,
				'component' => 'DashboardList',
				'params' => [
					'scopeCode' => ScopeService::BIC_SCOPE_STORE,
				],
			],
		];
	}
}
