<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Application\Features;

final class CollabPreviewSourcePointerReader
{
	private const USER_CHUNK_SIZE = 200;

	/** @var int[] */
	private array $userIds;

	/** @var array<int,array{sourceChatId:int,messageId:int}>|null */
	private ?array $pointers = null;

	/**
	 * @param int[] $userIds
	 */
	public function __construct(
		private readonly int $collabChatId,
		array $userIds,
	)
	{
		$this->userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}

	/**
	 * @return array<int,array{sourceChatId:int,messageId:int}>
	 */
	public function read(): array
	{
		if ($this->pointers !== null)
		{
			return $this->pointers;
		}

		$this->pointers = [];
		if (
			$this->collabChatId <= 0
			|| empty($this->userIds)
			|| !Features::isCollabPreviewSourceEnabled()
		)
		{
			return $this->pointers;
		}

		foreach (array_chunk($this->userIds, self::USER_CHUNK_SIZE) as $userIdChunk)
		{
			$result = RecentTable::query()
				->setSelect(['USER_ID', 'PREVIEW_SOURCE_CID', 'PREVIEW_SOURCE_MID'])
				->where('ITEM_CID', $this->collabChatId)
				->whereIn('USER_ID', $userIdChunk)
				->exec()
			;

			while ($row = $result->fetch())
			{
				$userId = (int)$row['USER_ID'];
				$this->pointers[$userId] = [
					'sourceChatId' => (int)($row['PREVIEW_SOURCE_CID'] ?? 0),
					'messageId' => (int)($row['PREVIEW_SOURCE_MID'] ?? 0),
				];
			}
		}

		return $this->pointers;
	}
}
