<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab;

use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;

class PromoteInviteeToEmployeeDto extends BaseDto
{
	public function __construct(
		public readonly int $projectId,
		public readonly int $inviteeId,
		public readonly int $departmentId,
	)
	{
	}

	public static function fromArray(array $args): self
	{
		return new self(
			projectId: NumberParser::parseLimitedInt($args['projectId'] ?? null, 'projectId', null, 1),
			inviteeId: NumberParser::parseLimitedInt($args['inviteeId'] ?? null, 'inviteeId', null, 1),
			departmentId: NumberParser::parseLimitedInt($args['departmentId'] ?? null, 'departmentId', null, 1),
		);
	}
}
