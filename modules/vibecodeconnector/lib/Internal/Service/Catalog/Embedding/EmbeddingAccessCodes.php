<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Embedding;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

final class EmbeddingAccessCodes
{
	private const USER_CODE_PREFIX = 'U';

	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessRepository $accessRepository = new AccessRepository(),
	) {}

	/**
	 * @return string[]
	 */
	public function getForItem(int $catalogItemId): array
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null)
		{
			throw new CatalogItemNotFoundException($catalogItemId);
		}

		return match ($item->getAccessType())
		{
			CatalogItemAccessType::Private => [$this->getOwnerCode($item)],
			CatalogItemAccessType::Public => [AccessCodes::ACCESS_CODE_AUTHORIZED],
			CatalogItemAccessType::ACL => $this->getAclCodes($item),
		};
	}

	/**
	 * @return string[]
	 */
	private function getAclCodes(CatalogItem $item): array
	{
		$codes = $this->accessRepository->getCodesForItem((int)$item->getId());

		return array_values(array_unique([...$codes, $this->getOwnerCode($item)]));
	}

	private function getOwnerCode(CatalogItem $item): string
	{
		return self::USER_CODE_PREFIX . $item->getOwnerId();
	}
}
