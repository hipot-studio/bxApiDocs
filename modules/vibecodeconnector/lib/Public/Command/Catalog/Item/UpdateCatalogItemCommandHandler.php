<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item;

use Bitrix\Main\ArgumentException;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\NotOwnerException;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\HiddenRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon\IconFileResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Writer\EntryWriter;

final class UpdateCatalogItemCommandHandler
{
	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly EntryWriter $writer = new EntryWriter(),
		private readonly AccessRepository $accessRepository = new AccessRepository(),
		private readonly IconFileResolver $iconFileResolver = new IconFileResolver(),
		private readonly HiddenRepository $hiddenRepository = new HiddenRepository(),
	)
	{
	}

	public function __invoke(UpdateCatalogItemCommand $command): CatalogItem
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

		$fields = $command->fields;

		if (array_key_exists('title', $fields))
		{
			$item->setTitle(trim((string)$fields['title']));
		}

		if (array_key_exists('description', $fields))
		{
			$item->setDescription($this->normalizeNullableString($fields['description']));
		}

		if (array_key_exists('editUrl', $fields))
		{
			$item->setEditUrl($this->normalizeNullableString($fields['editUrl']));
		}

		if (array_key_exists('viewUrl', $fields))
		{
			$item->setViewUrl($this->normalizeNullableString($fields['viewUrl']));
		}

		if (array_key_exists('chatId', $fields))
		{
			$item->setChatId($fields['chatId'] !== null ? (int)$fields['chatId'] : null);
		}

		if (array_key_exists('externalId', $fields))
		{
			$item->setExternalId($this->normalizeNullableString($fields['externalId']));
		}

		if (array_key_exists('accessType', $fields))
		{
			$accessType = CatalogItemAccessType::tryFrom((string)$fields['accessType']);
			if ($accessType === null)
			{
				throw new ArgumentException('Unsupported accessType value', 'accessType');
			}

			$item->setAccessType($accessType);
		}

		$this->assertViewUrlIsPresentForApplication($item->getType(), $item->getViewUrl());

		if (array_key_exists('iconUrl', $fields))
		{
			$rawIconUrl = is_string($fields['iconUrl']) ? $fields['iconUrl'] : null;
			$newIconFileId = $this->iconFileResolver->resolve($rawIconUrl, $item->getPairingIss());
			$this->writer->saveReplacingIcon($item, $newIconFileId);
		}
		else
		{
			$this->writer->save($item);
		}

		if ($item->getAccessType() !== CatalogItemAccessType::ACL)
		{
			$this->accessRepository->deleteAllForCatalogItem($command->catalogItemId);

			if ($item->getAccessType() === CatalogItemAccessType::Private)
			{
				$this->hiddenRepository->deleteAllForCatalogItem($command->catalogItemId);
			}
		}

		return $item;
	}

	private function assertViewUrlIsPresentForApplication(CatalogItemType $type, ?string $viewUrl): void
	{
		if ($type === CatalogItemType::Application && $viewUrl === null)
		{
			throw new ArgumentException('viewUrl is required for application items', 'viewUrl');
		}
	}

	private function normalizeNullableString(mixed $value): ?string
	{
		if (!is_string($value))
		{
			return null;
		}

		$value = trim($value);

		return $value === '' ? null : $value;
	}
}
