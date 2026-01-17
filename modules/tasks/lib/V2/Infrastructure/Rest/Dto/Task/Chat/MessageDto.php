<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\Chat;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\Task\Chat\MessageDtoMapper;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Message;

class MessageDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['send'])]
	public ?int $taskId;

	#[Editable]
	#[Required(['send'])]
	public ?string $text;

	public static function fromEntity(?Message $message, ?Request $request = null): ?self
	{
		if (!$message)
		{
			return null;
		}

		return (new MessageDtoMapper())->mapByMessageAndRequest($message, $request);
	}
}
