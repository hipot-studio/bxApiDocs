<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Dto;

use Bitrix\Vibecodeconnector\Public\Dto\CatalogItem;
use Bitrix\Vibecodeconnector\Public\Dto\CatalogItemCollection;

final class CatalogItemDtoMapper
{
	public function toDto(CatalogItem $item): CatalogItemDto
	{
		return new CatalogItemDto(
			id: $item->id,
			kind: $item->kind,
			title: $item->title,
			description: $item->description,
			iconUrl: $item->iconUrl,
			editUrl: $item->editUrl,
			viewUrl: $item->viewUrl,
			chatId: $item->chatId,
			externalId: $item->externalId,
			ownerId: $item->ownerId,
			ownerName: $item->ownerName,
			color: $item->color,
			counter: null,
			isPublished: !$item->isMine,
			isPinned: $item->isPinned,
			isMine: $item->isMine,
			isHidden: $item->isHidden,
			isNew: $item->isNew,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function toArrayList(CatalogItemCollection $collection): array
	{
		$result = [];
		foreach ($collection as $item)
		{
			$result[] = $this->toDto($item)->toArray();
		}

		return $result;
	}
}
