<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\ArrayParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\EmailParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\PhoneParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\StringParser;

class SendInvitationsDto extends BaseDto
{
	public function __construct(
		public readonly int $projectId,
		public readonly array $recipients,
	)
	{}

	public static function fromArray(array $args): self
	{
		$contacts = ArrayParser::parse($args['contacts'] ?? null, 'contacts');
		if (empty($contacts))
		{
			throw new McpException('Parameter "contacts" must be a non-empty array.');
		}

		if (count($contacts) > 100)
		{
			throw new McpException('No more than 100 contacts can be invited in one call.');
		}

		return new self(
			projectId: NumberParser::parseLimitedInt(
				$args['projectId'] ?? null,
				'projectId',
				null,
				1,
			),
			recipients: array_map(
				static fn ($contact, $index) => self::parseContact($contact, (int)$index),
				$contacts,
				array_keys($contacts),
			),
		);
	}

	private static function parseContact(mixed $contact, int $index): array
	{
		if (!is_array($contact))
		{
			throw new McpException("Parameter \"contacts[$index]\" must be an object.");
		}

		$email = null;
		try
		{
			$email = EmailParser::parse($contact['email'] ?? null, "contacts[$index].email");
		}
		catch (McpException $exception)
		{
			if (!array_key_exists('phoneNumber', $contact))
			{
				throw $exception;
			}
		}

		$phoneNumber = null;
		if ($email === null)
		{
			$phoneNumber = PhoneParser::parse(
				$contact['phoneNumber'] ?? null,
				"contacts[$index].phoneNumber",
			);
		}

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
