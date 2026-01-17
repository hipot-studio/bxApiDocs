<?php

declare(strict_types=1);

namespace Bitrix\Booking\Provider;

use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\Favorites\Favorites;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\FavoritesRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;

class FavoritesProvider
{
	private FavoritesRepositoryInterface $repository;
	private ResourceRepositoryInterface $resourceRepository;

	public function __construct()
	{
		$this->repository = Container::getFavoritesRepository();
		$this->resourceRepository = Container::getResourceRepository();
	}

	public function getList(
		int $managerId,
		DatePeriod $datePeriod = null,
		bool $withCounters = false,
		bool $withSku = false,
	): Favorites
	{
		$favorites = $this->repository->getList($managerId);

		if ($withCounters)
		{
			(new ResourceProvider())
				->withCounters(
					collection: $favorites->getResources(),
					managerId: $managerId,
					datePeriod: $datePeriod,
				);
		}

		if ($withSku)
		{
			$this->resourceRepository->withSkus($favorites->getResources());
		}

		return $favorites;
	}
}
