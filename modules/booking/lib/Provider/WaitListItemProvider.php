<?php

declare(strict_types=1);

namespace Bitrix\Booking\Provider;

use Bitrix\Booking\Entity\WaitListItem\WaitListItem;
use Bitrix\Booking\Entity\WaitListItem\WaitListItemCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\WaitListItemRepositoryInterface;
use Bitrix\Booking\Provider\Params\GridParams;
use Bitrix\Booking\Provider\Params\WaitListItem\WaitListItemFilter;
use Bitrix\Booking\Provider\Params\WaitListItem\WaitListItemSelect;
use Bitrix\Booking\Provider\Trait\ClientTrait;
use Bitrix\Booking\Provider\Trait\ExternalDataTrait;

class WaitListItemProvider
{
	use ClientTrait;
	use ExternalDataTrait;

	private WaitListItemRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getWaitListItemRepository();
	}

	public function getList(GridParams $gridParams, int $userId): WaitListItemCollection
	{
		return $this->repository->getList(
			limit: $gridParams->limit,
			offset: $gridParams->offset,
			filter: $gridParams->getFilter(),
			sort: $gridParams->getSort(),
			select: $gridParams->getSelect(),
			userId: $userId,
		);
	}

	public function getById(int $id, int $userId, bool $withRelations = true): WaitListItem|null
	{
		$waitListItem = $this->repository->getById($id, $userId);

		if (!$waitListItem)
		{
			return null;
		}

		if ($withRelations)
		{
			Container::getExternalDataService()->loadExternalData($waitListItem->getExternalDataCollection());
			Container::getClientService()->loadClientData($waitListItem->getClientCollection());
		}

		return $waitListItem;
	}
}
