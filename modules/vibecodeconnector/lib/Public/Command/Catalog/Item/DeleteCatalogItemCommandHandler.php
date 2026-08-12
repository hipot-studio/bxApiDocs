<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\NotOwnerException;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Writer\EntryWriter;

final class DeleteCatalogItemCommandHandler
{
	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly EntryWriter $writer = new EntryWriter(),
	)
	{
	}

	public function __invoke(DeleteCatalogItemCommand $command): CatalogItem
	{
		$item = $this->repository->getById($command->catalogItemId);
		if ($item === null)
		{
			throw new CatalogItemNotFoundException($command->catalogItemId);
		}

		if ($item->getOwnerId() !== $command->userId)
		{
			throw new NotOwnerException();
		}

		$this->writer->delete($item);

		return $item;
	}
}
