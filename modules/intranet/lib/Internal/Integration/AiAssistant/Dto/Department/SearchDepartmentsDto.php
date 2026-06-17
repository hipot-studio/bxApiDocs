<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Department;

use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\StringParser;

class SearchDepartmentsDto extends BaseDto
{
	public function __construct(
		public readonly ?string $departmentName = null,
		public readonly ?int $limit = null,
		public readonly ?int $offset = null,
	)
	{}

	public static function fromArray($args): self
	{
		return new self(
			departmentName: StringParser::parse($args['departmentName'] ?? null, 'departmentName'),
			limit: NumberParser::parseLimitedInt($args['limit'] ?? null, 'limit', 20, 1,50),
			offset: NumberParser::parseLimitedInt($args['offset'] ?? null, 'offset', 0),
		);
	}
}
