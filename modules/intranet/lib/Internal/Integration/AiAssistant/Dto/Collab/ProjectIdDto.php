<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab;

use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;

class ProjectIdDto extends BaseDto
{
	public function __construct(
		public readonly int $projectId,
	)
	{
	}

	public static function fromArray(array $args): self
	{
		return new self(
			projectId: NumberParser::parseLimitedInt($args['projectId'] ?? null, 'projectId', null, 1),
		);
	}
}
