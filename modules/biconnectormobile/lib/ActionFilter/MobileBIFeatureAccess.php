<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\ActionFilter;

use Bitrix\BIConnectorMobile\Service\ScopeVisibilityService;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Refuses an action while the feature is not available on the portal: the flag is off, or biconnector is
 * not installed at all - the module may outlive it, and then there is nothing to read reports from.
 * Tariff, tool and rights belong to BIConstructorAccess, which runs right after this filter, and an empty
 * scope is no reason to refuse at all.
 */
final class MobileBIFeatureAccess extends Base
{
	// Consumed by the biconnectormobile dashboard-list screen (detectNoAccessCode) to pick the "no access" screen text.
	public const ERROR_FEATURE_DISABLED = 'FEATURE_DISABLED';

	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!Loader::includeModule('biconnector') || !ScopeVisibilityService::isFeatureEnabled())
		{
			$this->addError(new Error(
				Loc::getMessage('BICONNECTORMOBILE_ACTION_FILTER_FEATURE_DISABLED') ?? '',
				self::ERROR_FEATURE_DISABLED,
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
