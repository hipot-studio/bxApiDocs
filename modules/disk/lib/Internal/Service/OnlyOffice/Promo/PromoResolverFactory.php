<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\PromoResolverInterface;
use Bitrix\Disk\Public\Provider\CustomServerAvailabilityProvider;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\License;
use Bitrix\Main\ObjectNotFoundException;

readonly class PromoResolverFactory
{
	protected ServiceLocator $serviceLocator;

	/**
	 * @param Environment $environment
	 * @param TariffGroupResolverFactory $tariffGroupResolverFactory
	 */
	public function __construct(
		protected Environment $environment,
		protected TariffGroupResolverFactory $tariffGroupResolverFactory,
	)
	{
		$this->serviceLocator = ServiceLocator::getInstance();
	}

	/**
	 * @return PromoResolverInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function make(): PromoResolverInterface
	{
		if ($this->environment->isCloudPortal())
		{
			return $this->makeForCloud();
		}

		return $this->makeForBox();
	}

	/**
	 * @return PromoCloudResolver
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	protected function makeForCloud(): PromoCloudResolver
	{
		return new PromoCloudResolver(
			environment: $this->environment,
			tariffGroupResolver: $this->tariffGroupResolverFactory->makeForCloud(),
			sessionBoostService: $this->serviceLocator->get(BaasSessionBoostService::class),
		);
	}

	/**
	 * @return PromoResolverInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	protected function makeForBox(): PromoResolverInterface
	{
		return new PromoBoxResolver(
			environment: $this->environment,
			tariffGroupResolver: $this->tariffGroupResolverFactory->makeForBox(),
			sessionBoostService: $this->serviceLocator->get(BaasSessionBoostService::class),
			customServerAvailabilityProvider: $this->serviceLocator->get(CustomServerAvailabilityProvider::class),
			license: new License(),
		);
	}
}
