<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Provider;

use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Common\RowsToMapHelper;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Message\Param;
use Bitrix\Im\V2\Reading\Counter\Entity\ChatsCounterMap;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Counts unread messages carrying any of the given message param names.
 * Chats in the overflow state are skipped: there is no exact counter to reduce.
 */
final class MessageParamUnreadCounterProvider
{
	/**
	 * Sized above the reachable list: counter_chats_limit (1000 by default) plus up to 100 open
	 * line counters. Still below the 3000-5000 ids where the plan stops using CHAT_ID in the
	 * index path by (USER_ID, CHAT_ID).
	 */
	private const CHAT_IDS_PER_QUERY = 1500;

	public function __construct(
		private readonly CounterOverflowService $overflowService,
	)
	{}

	/**
	 * Ask for the whole batch of chats at once: a call costs one query per CHAT_IDS_PER_QUERY chats
	 * to skip overflowed ones plus one counting query per CHAT_IDS_PER_QUERY chats left, so
	 * chat-by-chat calls pay both for every chat.
	 *
	 * Counters are not kept between calls: reading a chat or receiving a message changes them
	 * within the same request, and this provider gets no signal about either event.
	 *
	 * @param int[] $chatIds
	 * @param string[] $messageParamNames
	 */
	public function getForUserByChatIds(int $userId, array $chatIds, array $messageParamNames): ChatsCounterMap
	{
		$paramNames = Normalizer::toUniqueTrimmedStrings($messageParamNames, Param::MAX_NAME_LENGTH);
		$chatIds = Normalizer::toUniquePositiveIntegers($chatIds);
		if ($userId <= 0 || empty($chatIds) || empty($paramNames))
		{
			return ChatsCounterMap::fromArray([]);
		}

		$overflowedChatIds = $this->filterOverflowedChatIds($userId, $chatIds);
		$chatIdsToCount = array_values(array_diff($chatIds, $overflowedChatIds));
		$fetched = empty($chatIdsToCount) ? [] : $this->fetch($userId, $chatIdsToCount, $paramNames);

		// Grouped rows come back in no defined order, so the key order is restored here: the returned
		// map is always keyed in the requested chat order.
		$counters = [];
		foreach ($chatIds as $chatId)
		{
			$counter = $fetched[$chatId] ?? 0;
			if ($counter > 0)
			{
				$counters[$chatId] = $counter;
			}
		}

		return ChatsCounterMap::fromArray($counters);
	}

	/**
	 * @param int[] $chatIds
	 * @return int[]
	 */
	private function filterOverflowedChatIds(int $userId, array $chatIds): array
	{
		$overflowedChunks = [];
		foreach (array_chunk($chatIds, self::CHAT_IDS_PER_QUERY) as $chatIdsChunk)
		{
			$overflowedChunks[] = $this->overflowService->filterOverflowedChatIdsByUserId($chatIdsChunk, $userId);
		}

		return array_merge(...$overflowedChunks);
	}

	/**
	 * @param int[] $chatIds
	 * @param string[] $paramNames
	 * @return array<int, int>
	 */
	private function fetch(int $userId, array $chatIds, array $paramNames): array
	{
		$counters = [];
		foreach (array_chunk($chatIds, self::CHAT_IDS_PER_QUERY) as $chatIdsChunk)
		{
			$counters += $this->fetchChunk($userId, $chatIdsChunk, $paramNames);
		}

		return $counters;
	}

	/**
	 * @param int[] $chatIds
	 * @param string[] $paramNames
	 * @return array<int, int>
	 */
	private function fetchChunk(int $userId, array $chatIds, array $paramNames): array
	{
		$query = MessageUnreadTable::query()
			->registerRuntimeField(new Reference(
				'PARAM',
				MessageParamTable::class,
				Join::on('this.MESSAGE_ID', 'ref.MESSAGE_ID'),
				['join_type' => Join::TYPE_INNER],
			))
			->setSelect([
				'CHAT_ID',
				'MATCHED_COUNT' => new ExpressionField('MATCHED_COUNT', 'COUNT(DISTINCT %s)', ['MESSAGE_ID']),
			])
			->where('USER_ID', $userId)
			->whereIn('CHAT_ID', $chatIds)
			->whereIn('PARAM.PARAM_NAME', $paramNames)
			->setGroup(['CHAT_ID'])
		;

		return RowsToMapHelper::mapIntToInt($query->fetchAll(), 'CHAT_ID', 'MATCHED_COUNT');
	}
}
