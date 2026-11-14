<?php

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Link\PersonalPin\PersonalPinCollection;
use Bitrix\Im\V2\Link\Pin\PinCollection;
use Bitrix\Im\V2\Link\Pin\PinService;
use Bitrix\Main\Engine\CurrentUser;

class Pin extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Pin.tail
	 */
	public function tailAction(Chat $chat, array $filter = [], array $order = [], int $limit = 50): ?array
	{
		$pinLimit = $this->getLimit($limit);

		$pins = PinCollection::find(
			$this->preparePinFilter($chat, $filter),
			$this->preparePinOrder($order),
			$pinLimit
		);

		return $this->toRestFormatWithPaginationData([$pins], $pinLimit, $pins->count());
	}

	/**
	 * @restMethod im.v2.Chat.Pin.personalTail
	 */
	public function personalTailAction(
		Chat $chat,
		CurrentUser $user,
		array $filter = [],
		array $order = [],
		int $limit = 50
	): ?array
	{
		$pinLimit = $this->getLimit($limit);

		$pins = PersonalPinCollection::findForOwner(
			(int)$user->getId(),
			$this->preparePinFilter($chat, $filter),
			$this->preparePinOrder($order),
			$pinLimit
		);

		return $this->toRestFormatWithPaginationData([$pins], $pinLimit, $pins->count());
	}

	public function countAction(Chat $chat): ?array
	{
		return [
			'counter' => (new PinService())->getCount($chat->getChatId(), $chat->getStartId())
		];
	}

	/**
	 * START_ID keeps a pin of a message outside the history window of the reader out of the answer:
	 * the window may shrink after the pin was made, but the row survives the shift.
	 */
	private function preparePinFilter(Chat $chat, array $filter): array
	{
		$pinFilter = [
			'LAST_ID' => $filter['lastId'] ?? null,
			'CHAT_ID' => $chat->getChatId(),
			'START_ID' => $chat->getStartId() ?: null,
		];

		$searchMessage = is_string($filter['searchMessage'] ?? null) ? trim($filter['searchMessage']) : '';

		if ($searchMessage !== '')
		{
			$pinFilter['SEARCH_MESSAGE'] = $searchMessage;
		}

		return $pinFilter;
	}

	private function preparePinOrder(array $order): array
	{
		return [
			'ID' => $order['id'] ?? 'DESC'
		];
	}
}
