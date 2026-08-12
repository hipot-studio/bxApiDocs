<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Dto;

final class CatalogItem
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
		public readonly ?string $externalId,
		public readonly int $ownerId,
		public readonly string $color,
		public readonly bool $isPinned,
		public readonly bool $isMine,
		public readonly ?int $createdAt,
		public readonly ?string $ownerName = null,
		public readonly bool $isHidden = false,
		public readonly bool $isNew = false,
	) {}
}
