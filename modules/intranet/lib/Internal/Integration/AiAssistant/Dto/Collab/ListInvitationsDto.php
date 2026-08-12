<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab;

use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\EnumParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\StringParser;

class ListInvitationsDto extends BaseDto
{
	public function __construct(
		public readonly int $projectId,
		public readonly array $statuses,
		public readonly array $channels,
		public readonly ?string $query,
		public readonly int $limit,
		public readonly int $cursor,
	)
	{
	}

	public static function fromArray(array $args): self
	{
		return new self(
			projectId: NumberParser::parseLimitedInt($args['projectId'] ?? null, 'projectId', null, 1),
			statuses: EnumParser::parseArray('statuses', InvitationStatus::class, $args['statuses'] ?? []),
			channels: EnumParser::parseArray('channels', InvitationType::class, $args['channels'] ?? []),
			query: StringParser::parse($args['query'] ?? null, 'query'),
			limit: NumberParser::parseLimitedInt($args['limit'] ?? null, 'limit', 20, 1, 100),
			cursor: NumberParser::parseLimitedInt($args['cursor'] ?? null, 'cursor', 0),
		);
	}
}
