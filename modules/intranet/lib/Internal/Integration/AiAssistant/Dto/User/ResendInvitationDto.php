<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\EmailParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\PhoneParser;

class ResendInvitationDto extends BaseDto
{
	public function __construct(
		public readonly ?int $filterUserId = null,
		public readonly ?string $email = null,
		public readonly ?string $phoneNumber = null,
	)
	{}

	public static function fromArray(array $args): self
	{
		$filterUserId = NumberParser::parseLimitedInt(
			$args['filterUserId'] ?? null,
			'filterUserId',
			null,
			1,
		);
		$email = EmailParser::parse($args['email'] ?? null, 'email');
		$phoneNumber = PhoneParser::parse($args['phoneNumber'] ?? null, 'phoneNumber');

		if (
			$filterUserId === null
			&& empty($email)
			&& empty($phoneNumber)
		)
		{
			throw new McpException(
				'One of "filterUserId", "email", or "phoneNumber" must be provided.'
			);
		}

		return new self(
			$filterUserId,
			$email,
			$phoneNumber,
		);
	}
}
