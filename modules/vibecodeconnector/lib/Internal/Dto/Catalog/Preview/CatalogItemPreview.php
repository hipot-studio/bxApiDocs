<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Preview;

final readonly class CatalogItemPreview
{
	public function __construct(
		public string $title,
		public string $description,
		public string $color,
		public string $pageUrl,
		public ?string $iconUrl,
		public string $authorName,
		public ?string $authorAvatarUrl,
		public ?string $authorProfileUrl,
		public ?int $appChatId = null,
	) {
	}
}
