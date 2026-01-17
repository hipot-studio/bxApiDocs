<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\Task\Chat;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\Chat\MessageDto;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Message;

class MessageDtoMapper
{
	public function getMessageByDto(MessageDto $dto): Message
	{
		$dtoArray = $dto->toArray(true);

		return Message::mapFromArray($dtoArray);
	}

	public function mapByMessageAndRequest(?Message $message, ?Request $request = null): ?MessageDto
	{
		if (!$message)
		{
			return null;
		}

		$select = $request?->select?->getList(true) ?? [];
		$dto = new MessageDto();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $message->id;
		}
		if (empty($select) || in_array('text', $select, true))
		{
			$dto->text = $message->text;
		}

		return $dto;
	}
}
