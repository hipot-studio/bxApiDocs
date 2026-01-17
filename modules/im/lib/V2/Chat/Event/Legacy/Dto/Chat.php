<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Event\Legacy\Dto;

class Chat
{
	protected \Bitrix\Im\V2\Chat $chat;
	protected int $userId;

	public function __construct(\Bitrix\Im\V2\Chat $chat, int $userId)
	{
		$this->chat = $chat->withContextUser($userId);
		$this->userId = $userId;
	}

	public function toArray(): array
	{
		$options = \CIMChat::GetChatOptions();
		$restrictions = $options['DEFAULT'];
		$entityType = $this->chat->getEntityType();

		if ($entityType && array_key_exists($entityType, $options))
		{
			$restrictions = $options[$entityType];
		}

		return [
			'ID' => $this->chat->getId(),
			'PARENT_CHAT_ID' => (int)$this->chat->getParentChatId(),
			'PARENT_MESSAGE_ID' => $this->chat->getParentMessageId(),
			'NAME' => $this->chat->getTitle(),
			'DESCRIPTION' => $this->chat->getDescription(),
			'OWNER' => (int)$this->chat->getAuthorId(),
			'EXTRANET' => $this->chat->getExtranet(),
			'AVATAR' => $this->chat->getAvatar(),
			'COLOR' => $this->chat->getColor(true),
			'TYPE' => $this->chat->getExtendedType(),
			'COUNTER' => $this->chat->getUserCounter(),
			'USER_COUNTER' => $this->chat->getUserCount(),
			'MESSAGE_COUNT' => $this->chat->getMessageCount(),
			'UNREAD_ID' => $this->chat->getUnreadId(),
			'RESTRICTIONS' => $restrictions,
			'LAST_MESSAGE_ID' => $this->chat->getLastMessageId(),
			'LAST_ID' => $this->chat->getLastId(),
			'MARKED_ID' => $this->chat->getMarkedId(),
			'DISK_FOLDER_ID' => (int)$this->chat->getDiskFolderId(),
			'ENTITY_TYPE' => (string)$this->chat->getEntityType(),
			'ENTITY_ID' => (string)$this->chat->getEntityId(),
			'ENTITY_DATA_1' => (string)$this->chat->getEntityData1(),
			'ENTITY_DATA_2' => (string)$this->chat->getEntityData2(),
			'ENTITY_DATA_3' => (string)$this->chat->getEntityData3(),
			'MUTE_LIST' => $this->chat->getMuteList(),
			'DATE_CREATE' => $this->chat->getDateCreate(),
			'MESSAGE_TYPE' => $this->chat->getType(),
			'PUBLIC' => $this->chat->getPublicOption(),
			'ROLE' => mb_strtolower($this->chat->getRole()),
			'ENTITY_LINK' => $this->chat->getEntityLink()->toArray(),
			'TEXT_FIELD_ENABLED' => $this->chat->getTextFieldEnabled()->get(),
			'BACKGROUND_ID' => $this->chat->getBackground()->get(),
			'PERMISSIONS' => [
				'MANAGE_USERS_ADD' => mb_strtolower((string)$this->chat->getManageUsersAdd()),
				'MANAGE_USERS_DELETE' => mb_strtolower((string)$this->chat->getManageUsersDelete()),
				'MANAGE_UI' => mb_strtolower((string)$this->chat->getManageUI()),
				'MANAGE_SETTINGS' => mb_strtolower((string)$this->chat->getManageSettings()),
				'MANAGE_MESSAGES' => mb_strtolower((string)$this->chat->getManageMessages()),
				'CAN_POST' => mb_strtolower((string)$this->chat->getManageMessages()),
			],
			'IS_NEW' => $this->chat->isNew(),
		];
	}
}
