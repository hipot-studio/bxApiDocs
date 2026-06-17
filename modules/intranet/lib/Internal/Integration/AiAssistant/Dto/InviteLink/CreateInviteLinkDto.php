<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\InviteLink;

use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\ArrayParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;

class CreateInviteLinkDto extends BaseDto
{
	public function __construct(
		public readonly array $departmentsId = [],
	)
	{}

	public static function fromArray($args): self
	{
		$departmentsId = ArrayParser::parse($args['departmentsId'] ?? null, 'departmentsId');
		foreach ($departmentsId as $index => $id)
		{
			NumberParser::parseLimitedInt($id, "departmentsId[$index]", 0);
		}

		return new self($departmentsId);
	}
}
