<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission;

use Bitrix\Rest\Internal\Service\Admission\Strategy\AlwaysAvailableMethodStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\EventGrantingStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\InactiveRulesStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\Portal\MarketSubscriptionStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\Portal\TariffStrategy;
use Bitrix\Rest\Public\Event\RestAccessGrantEvent;
use Bitrix\Rest\Service\ServiceContainer;

final class PortalAdmissionGate extends BaseAdmissionGate
{
	protected function getDenialStrategies(): iterable
	{
		yield new InactiveRulesStrategy();
		yield new AlwaysAvailableMethodStrategy(\CRestServer::instance()?->getMethod());

		$tariffAccessService = ServiceContainer::getInstance()->getVibePlusTariffAccessService();

		yield new TariffStrategy($tariffAccessService);
		yield new MarketSubscriptionStrategy($tariffAccessService);
	}

	protected function getGrantingStrategies(): iterable
	{
		return [
			new EventGrantingStrategy(
				RestAccessGrantEvent::AUTH_TYPE_OAUTH,
				null,
				null,
				\CRestServer::instance(),
			),
		];
	}
}
