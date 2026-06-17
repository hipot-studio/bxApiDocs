<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User;

use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\EmailParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\StringParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;

class InviteByEmailDto extends BaseDto
{
	public function __construct(
		public readonly string $email,
		public readonly ?string $firstName = null,
		public readonly ?string $lastName = null,
		public readonly ?int $departmentId = null,
	)
	{}

	public static function fromArray(array $args): self
	{
		return new self(
			email: EmailParser::parse($args['email'] ?? null, 'email', true),
			firstName: StringParser::parse($args['firstName'] ?? null, 'firstName'),
			lastName: StringParser::parse($args['lastName'] ?? null, 'lastName'),
			departmentId: NumberParser::parseLimitedInt(
				$args['departmentId'] ?? null,
				'departmentId',
				null,
				1,
			),
		);
	}
}
