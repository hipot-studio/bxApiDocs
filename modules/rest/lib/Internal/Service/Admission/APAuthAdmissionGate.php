<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission;

use Bitrix\Rest\Internal\Service\Admission\Strategy\AlwaysAvailableMethodStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\APAuth\InactiveRulesStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\APAuth\MarketSubscriptionStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\APAuth\TariffStrategy;
use Bitrix\Rest\Internal\Service\Admission\Strategy\EventGrantingStrategy;
use Bitrix\Rest\Public\Event\RestAccessGrantEvent;
use Bitrix\Rest\Service\ServiceContainer;

final class APAuthAdmissionGate extends BaseAdmissionGate
{
	public function __construct(private readonly int $passwordId)
	{
	}

	protected function getDenialStrategies(): iterable
	{
		$restServer = \CRestServer::instance();

		yield new InactiveRulesStrategy();
		yield new AlwaysAvailableMethodStrategy($restServer?->getMethod());

		$container = ServiceContainer::getInstance();
		$tariffAccessService = $container->getVibePlusTariffAccessService();
		$passwordService = $container->getAPAuthPasswordService();

		yield new TariffStrategy($this->passwordId, $tariffAccessService, $passwordService, $restServer);
		yield new MarketSubscriptionStrategy($this->passwordId, $tariffAccessService, $passwordService, $restServer);
	}

	protected function getGrantingStrategies(): iterable
	{
		return [
			new EventGrantingStrategy(
				RestAccessGrantEvent::AUTH_TYPE_APAUTH,
				null,
				$this->passwordId,
				\CRestServer::instance(),
			),
		];
	}
}
