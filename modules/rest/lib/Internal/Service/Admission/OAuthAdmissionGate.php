<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission;

use Bitrix\Rest\Internal\Service\Admission\Strategy\AlwaysAvailableMethodStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\EventGrantingStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\InactiveRulesStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\OAuth\MarketSubscriptionStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\OAuth\TariffStrategy;
use Bitrix\Rest\Public\Event\RestAccessGrantEvent;
use Bitrix\Rest\Service\ServiceContainer;

final class OAuthAdmissionGate extends BaseAdmissionGate
{
	public function __construct(private readonly string $clientId)
	{
	}

	protected function getDenialStrategies(): iterable
	{
		yield new InactiveRulesStrategy();
		yield new AlwaysAvailableMethodStrategy(\CRestServer::instance()?->getMethod());

		$tariffAccessService = ServiceContainer::getInstance()->getVibePlusTariffAccessService();

		yield new TariffStrategy($this->clientId, $tariffAccessService);
		yield new MarketSubscriptionStrategy($this->clientId, $tariffAccessService);
	}

	protected function getGrantingStrategies(): iterable
	{
		return [
			new EventGrantingStrategy(
				RestAccessGrantEvent::AUTH_TYPE_OAUTH,
				$this->clientId,
				null,
				\CRestServer::instance(),
			),
		];
	}
}
