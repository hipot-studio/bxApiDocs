<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class SearchUsersDto
{
	use MapTypeTrait;

	public function __construct(
		#[NotEmpty]
		public readonly ?array $searchQueries = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			searchQueries: static::mapArray($props, 'searchQueries'),
		);
	}
}
