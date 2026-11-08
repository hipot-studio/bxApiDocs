<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class GetProjectMeetingsDto
{
	use MapTypeTrait;

	public const LIMIT_MIN = 1;
	public const LIMIT_MAX = 50;
	public const LIMIT_DEFAULT = 10;

	// Range is declared on the property instead of the promoted constructor parameter:
	// ValidationService reads property attributes, so the range is still enforced, while
	// SchemaConsistencyTest treats constructor-parameter validation attributes as required
	// schema fields, and limit is optional (it has a default).
	#[Range(min: self::LIMIT_MIN, max: self::LIMIT_MAX)]
	public readonly int $limit;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $projectId = null,
		?int $limit = null,
		public readonly ?string $from = null,
		public readonly ?string $to = null,
	)
	{
		$this->limit = $limit ?? self::LIMIT_DEFAULT;
	}

	public static function fromArray(array $props): self
	{
		return new self(
			projectId: static::mapInteger($props, 'projectId'),
			limit: static::mapInteger($props, 'limit'),
			from: static::mapString($props, 'from'),
			to: static::mapString($props, 'to'),
		);
	}
}
