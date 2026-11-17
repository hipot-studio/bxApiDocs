<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadChatsByTypeBatchTasksTask;

use Bitrix\Main\Config\Option;

final class ChatIdsChunker
{
	private const DEFAULT_CHUNK_SIZE = 200;
	private const OPTION_CHUNK_SIZE = 'read_chats_by_type_chunk_size';

	public static function split(array $chatIds): array
	{
		$chunkSize = (int)Option::get('tasks', self::OPTION_CHUNK_SIZE, self::DEFAULT_CHUNK_SIZE);
		$chunkSize = $chunkSize > 0 ? $chunkSize : self::DEFAULT_CHUNK_SIZE;

		return array_chunk($chatIds, $chunkSize);
	}
}
