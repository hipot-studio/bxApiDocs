<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\ArrayParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;

class InvitationIdsDto extends BaseDto
{
	public function __construct(
		public readonly int $projectId,
		public readonly array $invitationIds,
	)
	{}

	public static function fromArray(array $args): self
	{
		$invitationIds = ArrayParser::parse($args['invitationIds'] ?? null, 'invitationIds');
		if (empty($invitationIds))
		{
			throw new McpException('Parameter "invitationIds" must be a non-empty array.');
		}

		if (count($invitationIds) > 100)
		{
			throw new McpException('No more than 100 invitation IDs can be processed in one call.');
		}

		$result = [];
		foreach ($invitationIds as $index => $invitationId)
		{
			$result[] = NumberParser::parseLimitedInt(
				$invitationId,
				"invitationIds[$index]",
				null,
				1,
			);
		}

		return new self(
			projectId: NumberParser::parseLimitedInt($args['projectId'] ?? null, 'projectId', null, 1),
			invitationIds: array_values(array_unique($result)),
		);
	}
}
