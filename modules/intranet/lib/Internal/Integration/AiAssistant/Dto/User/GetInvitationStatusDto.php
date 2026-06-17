<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\BaseDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\EmailParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\StringParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\NumberParser;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\EnumParser;

class GetInvitationStatusDto extends BaseDto
{
	public function __construct(
		public readonly int $limit,
		public readonly int $offset,
		public readonly ?InvitationStatus $invitationStatus = null,
		public readonly ?string $userEmail = null,
		public readonly ?int $filterUserId = null,
		public readonly ?string $departmentName = null,
		public readonly ?int $departmentId = null,
	)
	{}

	public static function fromArray(array $args): self
	{
		$invitationStatus = EnumParser::parseNullableStringEnum(
			$args['invitationStatus'] ?? null,
			'invitationStatus',
			InvitationStatus::class,
		);

		if (
			$invitationStatus !== null
			&& $invitationStatus !== InvitationStatus::INVITED
			&& $invitationStatus !== InvitationStatus::INVITE_AWAITING_APPROVE
		)
		{
			throw new McpException(
				"Invalid invitationStatus value: {$invitationStatus->value}. "
				. "Allowed values are: INVITED, INVITE_AWAITING_APPROVE. "
			);
		}

		return new self(
			limit: NumberParser::parseLimitedInt(
				$args['limit'] ?? null,
				'limit',
				20,
				1,
				50,
			),
			offset: NumberParser::parseLimitedInt($args['offset'] ?? null, 'offset', 0),
			invitationStatus: $invitationStatus,
			userEmail: EmailParser::parse($args['userEmail'] ?? null, 'userEmail'),
			filterUserId: NumberParser::parseLimitedInt(
				$args['filterUserId'] ?? null,
				'filterUserId',
				null,
				1,
			),
			departmentName: StringParser::parse($args['departmentName'] ?? null, 'departmentName'),
			departmentId: NumberParser::parseLimitedInt(
				$args['departmentId'] ?? null,
				'departmentId',
				null,
			),
		);
	}
}
