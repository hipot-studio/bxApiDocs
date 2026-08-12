<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab;

use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\StringParser;

class SearchCollabsDto extends BaseDto
{
	public function __construct(
		public readonly string $projectName,
		public readonly int $limit,
		public readonly int $offset,
	)
	{
	}

	public static function fromArray(array $args): self
	{
		return new self(
			projectName: StringParser::parse($args['projectName'] ?? null, 'projectName', true),
			limit: NumberParser::parseLimitedInt($args['limit'] ?? null, 'limit', 20, 1, 50),
			offset: NumberParser::parseLimitedInt($args['offset'] ?? null, 'offset', 0),
		);
	}
}
