<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\PhoneParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\StringParser;

class InviteByPhoneDto extends BaseDto
{
	public function __construct(
		public readonly string $phoneNumber,
		public readonly ?string $firstName = null,
		public readonly ?string $lastName = null,
		public readonly ?int $departmentId = null,
	)
	{}

	public static function fromArray(array $args): self
	{
		return new self(
			phoneNumber: PhoneParser::parse($args['phoneNumber'] ?? null, 'phoneNumber', true),
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
