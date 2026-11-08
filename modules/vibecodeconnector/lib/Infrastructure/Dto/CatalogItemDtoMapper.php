<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Dto;

use Bitrix\Vibecodeconnector\Public\Dto\CatalogItem;
use Bitrix\Vibecodeconnector\Public\Dto\CatalogItemCollection;

final class CatalogItemDtoMapper
{
	public function toDto(CatalogItem $item, bool $isAdmin = false): CatalogItemDto
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
			appChatId: $item->appChatId,
			externalId: $item->externalId,
			ownerId: $item->ownerId,
			ownerName: $item->ownerName,
			color: $item->color,
			counter: null,
			isPublished: !$item->isMine,
			isPinned: $item->isPinned,
			isMine: $item->isMine,
			accessType: $item->accessType,
			launched: $item->launched,
			appChatAccessible: $item->appChatAccessible,
			appChatCreatable: $item->appChatCreatable,
			appChatWritingAllowed: $item->appChatWritingAllowed,
			isHidden: $item->isHidden,
			isFavorite: $item->isFavorite,
			isNew: $item->isNew,
			isDescriptionDefault: $item->isDescriptionDefault,
			canShare: $item->kind === 'application' && ($item->isMine || $isAdmin),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function toArrayList(CatalogItemCollection $collection, bool $isAdmin = false): array
	{
		$result = [];
		foreach ($collection as $item)
		{
			$result[] = $this->toDto($item, $isAdmin)->toArray();
		}

		return $result;
	}
}
