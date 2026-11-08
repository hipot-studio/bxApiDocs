<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;

final class AppChatOwnerFlagsResolver
{
	public function __construct(
		private readonly AppChatOnDemandService $onDemandService = new AppChatOnDemandService(),
		private readonly AppChatWriteAccessService $writeAccessService = new AppChatWriteAccessService(),
	) {}

	/**
	 * @param CatalogItem[] $items
	 * @return array<int, array{creatable: bool, writingAllowed: bool|null}>
	 */
	public function resolve(array $items, int $userId): array
	{
		$ownItems = array_values(array_filter(
			$items,
			static fn (CatalogItem $item): bool => $item->getOwnerId() === $userId
				&& $item->getType() === CatalogItemType::Application,
		));

		if ($ownItems === [])
		{
			return [];
		}

		$writingAllowed = $this->writeAccessService->resolveWritingAllowed($ownItems);

		$flags = [];
		foreach ($ownItems as $item)
		{
			$itemId = (int)$item->getId();
			$creatable = $this->onDemandService->isChatCreatableForOwner($item, $userId);

			$flags[$itemId] = [
				'creatable' => $creatable,
				'writingAllowed' => $creatable ? null : ($writingAllowed[$itemId] ?? null),
			];
		}

		return $flags;
	}
}
