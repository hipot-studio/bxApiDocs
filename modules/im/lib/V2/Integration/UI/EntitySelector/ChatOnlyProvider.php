<?php

namespace Bitrix\Im\V2\Integration\UI\EntitySelector;

use Bitrix\Im\V2\Chat;

/**
 * Provider for chats in a broad sense - entities from ChatTable, like chats and channels
 */
class ChatOnlyProvider extends RecentProvider
{
	protected const ENTITY_ID = 'im-chat-only';

	/**
	 * Only chats and channels allowed
	 */
	protected const ALLOWED_SEARCH_CHAT_TYPES = [
		Chat::IM_TYPE_CHAT,
		Chat::IM_TYPE_OPEN,
		Chat::IM_TYPE_CHANNEL,
		Chat::IM_TYPE_OPEN_CHANNEL,
	];

	public function __construct(array $options = [])
	{
		$options[SearchOptions::INCLUDE_ONLY_OPTION] = [SearchOptions::FLAG_CHATS];
		$options[SearchOptions::ONLY_WITH_MANAGE_USERS_ADD_RIGHT_OPTION] = true;
		$options[SearchOptions::ONLY_WITH_NULL_ENTITY_TYPE_OPTION] = true;

		parent::__construct($options);
	}

	/**
	 * Get only chats as default items
	 *
	 * @return array
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	protected function getDefaultDialogItems(): array
	{
		$chatIds = $this->getCommonChatQueryWithOrder()
			->fetchCollection()
			?->getIdList()
			?? []
		;

		return array_map(static fn (int $chatId): string => 'chat' . $chatId, $chatIds);
	}
}
