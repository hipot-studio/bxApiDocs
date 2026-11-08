<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\DateFormatter;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\TextFormatter;

class ChatMessageResponseMapper
{
	private const MESSAGE_TEXT_MAX_LENGTH = 200;

	public function __construct(
		private readonly UserResponseMapper $userResponseMapper,
	)
	{
	}

	public function mapFromArrays(array $messages, int $userId): array
	{
		return array_map(fn(array $message): array => $this->mapFromArray($message, $userId), $messages);
	}

	public function mapFromArray(array $message, int $userId): array
	{
		return [
			'text' => TextFormatter::truncate($message['TEXT'] ?? null, self::MESSAGE_TEXT_MAX_LENGTH),
			'author' => $this->userResponseMapper->mapFromIdAndName(
				$message['USER_ID'] ?? null,
				$message['USER_NAME'] ?? null,
			),
			'createdDate' => DateFormatter::formatDateTime(
				$message['CREATED_DATE'] ?? null,
				DateFormatter::FORMAT_WITHOUT_TIMEZONE,
				$userId,
			),
		];
	}
}
