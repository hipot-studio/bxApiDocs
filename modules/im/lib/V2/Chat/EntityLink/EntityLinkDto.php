<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\EntityLink;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Url;

final class EntityLinkDto
{
	public function __construct(
		#[NotEmpty]
		public readonly string $type,

		#[NotEmpty]
		public readonly int $chatId,

		#[NotEmpty]
		public readonly string $entityId,

		#[Url]
		public readonly ?string $url = null,
	) {
	}
}
