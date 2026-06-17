<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\EmailParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\PhoneParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\StringParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\ArrayParser;

class BulkInviteDto extends BaseDto
{
	public function __construct(
		public readonly array $contacts,
		public readonly ?int $departmentId = null,
	)
	{}

	public static function fromArray(array $args): self
	{
		$contacts = ArrayParser::parse($args['contacts'] ?? null, 'contacts');
		if (empty($contacts))
		{
			throw new McpException('Parameter "contacts" must be a non-empty array.');
		}

		return new self(
			contacts: array_map(
				static fn($contact, $index) => self::parseContact($contact, (int)$index),
				$contacts,
				array_keys($contacts),
			),
			departmentId: NumberParser::parseLimitedInt(
				$args['departmentId'] ?? null,
				'departmentId',
				null,
				1,
			),
		);
	}

	private static function parseContact(mixed $contact, int $index): array
	{
		if (!is_array($contact))
		{
			throw new McpException("Parameter \"contacts[$index]\" must be an object.");
		}

		$email = EmailParser::parse($contact['email'] ?? null, "contacts[$index].email");
		$phoneNumber = PhoneParser::parse(
			$contact['phoneNumber'] ?? null,
			"contacts[$index].phoneNumber",
		);

		if (empty($email) && empty($phoneNumber))
		{
			throw new McpException(
				"Parameter \"contacts[$index]\" must contain at least one of \"email\" or \"phoneNumber\"."
			);
		}

		return [
			'email' => $email,
			'phoneNumber' => $phoneNumber,
			'firstName' => StringParser::parse($contact['firstName'] ?? null, "contacts[$index].firstName"),
			'lastName' => StringParser::parse($contact['lastName'] ?? null, "contacts[$index].lastName"),
		];
	}
}
