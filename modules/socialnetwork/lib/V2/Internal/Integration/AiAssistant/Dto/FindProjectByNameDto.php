<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Validation\Rule\Count;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Validation\Rule\ElementsLength;

class FindProjectByNameDto
{
	use MapTypeTrait;

	public const MIN_QUERY_LENGTH = 2;
	public const MAX_QUERY_LENGTH = 255;
	public const MAX_QUERIES = 5;

	private function __construct(
		#[NotEmpty]
		#[Count(max: self::MAX_QUERIES)]
		#[ElementsType(Type::String)]
		#[ElementsLength(min: self::MIN_QUERY_LENGTH, max: self::MAX_QUERY_LENGTH)]
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
