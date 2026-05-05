<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\TariffGroupResolverInterface;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;

readonly class TariffGroupResolverFactory
{
	/**
	 * @param Environment $environment
	 */
	public function __construct(
		protected Environment $environment,
	)
	{
	}

	/**
	 * @return TariffGroupResolverInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function make(): TariffGroupResolverInterface
	{
		if ($this->environment->isCloudPortal())
		{
			return $this->makeForCloud();
		}

		return $this->makeForBox();
	}

	/**
	 * @return TariffGroupResolverInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function makeForCloud(): TariffGroupResolverInterface
	{
		return $this->makeInternal(TariffGroupCloudResolver::class);
	}

	/**
	 * @return TariffGroupResolverInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function makeForBox(): TariffGroupResolverInterface
	{
		return $this->makeInternal(TariffGroupBoxResolver::class);
	}

	/**
	 * @param class-string<TariffGroupResolverInterface> $className
	 * @return TariffGroupResolverInterface
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	protected function makeInternal(string $className): TariffGroupResolverInterface
	{
		return ServiceLocator::getInstance()->get($className);
	}
}
