<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Dto;

use Bitrix\Main\Localization\Loc;

/**
 * Catalog item card — for the local (AJAX) Catalog controller.
 * POPO with no dependency on the V3 DTO framework: the catalog is not
 * exposed outside via REST API, and V3's overhead (validation, schemas,
 * filters) is unnecessary here.
 */
final class CatalogItemDto
{
	public function __construct(
		public readonly int $id,
		public readonly string $kind,
		public readonly string $title,
		public readonly ?string $description,
		public readonly ?string $iconUrl,
		public readonly ?string $editUrl,
		public readonly ?string $viewUrl,
		public readonly ?int $chatId,
		public readonly ?int $appChatId,
		public readonly ?string $externalId,
		public readonly int $ownerId,
		public readonly ?string $ownerName,
		public readonly string $color,
		public readonly ?int $counter,
		public readonly bool $isPublished,
		public readonly bool $isPinned,
		public readonly bool $isMine,
		public readonly string $accessType,
		public readonly bool $launched,
		public readonly bool $appChatAccessible,
		public readonly bool $appChatCreatable = false,
		public readonly ?bool $appChatWritingAllowed = null,
		public readonly bool $isHidden = false,
		public readonly bool $isFavorite = false,
		public readonly bool $isNew = false,
		public readonly bool $isDescriptionDefault = false,
		public readonly bool $canShare = false,
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'kind' => $this->kind,
			'type' => $this->kind,
			'title' => $this->title,
			'description' => $this->description,
			'iconUrl' => $this->iconUrl,
			'editUrl' => $this->editUrl,
			'viewUrl' => $this->viewUrl,
			'chatId' => $this->chatId,
			'appChatId' => $this->appChatId,
			'externalId' => $this->externalId,
			'ownerId' => $this->ownerId,
			'ownerName' => $this->ownerName,
			'owner' => $this->getOwner(),
			'color' => $this->color,
			'counter' => $this->counter,
			'isPublished' => $this->isPublished,
			'isPinned' => $this->isPinned,
			'isMine' => $this->isMine,
			'accessType' => $this->accessType,
			'launched' => $this->launched,
			'appChatAccessible' => $this->appChatAccessible,
			'appChatCreatable' => $this->appChatCreatable,
			'appChatWritingAllowed' => $this->appChatWritingAllowed,
			'isHidden' => $this->isHidden,
			'isFavorite' => $this->isFavorite,
			'isNew' => $this->isNew,
			'isDescriptionDefault' => $this->isDescriptionDefault,
			'canShare' => $this->canShare,
		];
	}

	/**
	 * @return array{id: int|null, title: string}
	 */
	private function getOwner(): array
	{
		if ($this->isMine)
		{
			return [
				'id' => $this->ownerId,
				'title' => (string)Loc::getMessage('VIBECODECONNECTOR_CATALOG_ITEM_AUTHOR_YOU'),
			];
		}

		if ($this->ownerName !== null && trim($this->ownerName) !== '')
		{
			return [
				'id' => $this->ownerId,
				'title' => $this->ownerName,
			];
		}

		return [
			'id' => null,
			'title' => (string)Loc::getMessage('VIBECODECONNECTOR_CATALOG_ITEM_AUTHOR_UNKNOWN'),
		];
	}
}
