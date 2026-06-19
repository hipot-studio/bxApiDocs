<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Dto;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Main\DI\ServiceLocator;

final class ChatCreateFieldsDto
{
	private function __construct(
		public readonly string $type,
		public readonly ?string $entityType,
		public readonly ?string $entityId,
		public readonly ?string $entityData1,
		public readonly ?string $entityData2,
		public readonly ?string $entityData3,
		public readonly ?string $title,
		public readonly ?string $description,
		public readonly ?string $searchable,
		public readonly ?string $color,
		public readonly ?int $ownerId,
		public readonly ?array $users,
		public readonly ?array $managers,
		public readonly ?string $manageUsersAdd,
		public readonly ?string $manageUsersDelete,
		public readonly ?string $manageUi,
		public readonly ?string $manageSettings,
		public readonly ?int $messagesAutoDeleteDelay,
		public readonly ?string $manageMessages,
		public readonly mixed $avatar,
		public readonly ?string $conferencePassword,
		public readonly ?array $memberEntities,
		public readonly ?int $parentChatId,
		public readonly ?string $copilotMainRole,
	)
	{
	}

	public static function create(array $fields): self
	{
		$type = self::resolveType($fields['type'] ?? null);
		$entityType = self::resolveEntityType($fields['entityType'] ?? null);

		$conferencePassword = null;
		if ($entityType === Chat::ENTITY_TYPE_VIDEOCONF && isset($fields['conferencePassword']))
		{
			$conferencePassword = $fields['conferencePassword'];
		}

		$parentChatId = null;
		if (isset($fields['parentChatId']) && (int)$fields['parentChatId'] > 0)
		{
			$parentChatId = (int)$fields['parentChatId'];
		}

		return new self(
			type: $type,
			entityType: $entityType,
			entityId: $fields['entityId'] ?? null,
			entityData1: $fields['entityData1'] ?? null,
			entityData2: $fields['entityData2'] ?? null,
			entityData3: $fields['entityData3'] ?? null,
			title: $fields['title'] ?? null,
			description: $fields['description'] ?? null,
			searchable: $fields['searchable'] ?? null,
			color: $fields['color'] ?? null,
			ownerId: isset($fields['ownerId']) ? (int)$fields['ownerId'] : null,
			users: $fields['users'] ?? null,
			managers: $fields['managers'] ?? null,
			manageUsersAdd: $fields['manageUsersAdd'] ?? null,
			manageUsersDelete: $fields['manageUsersDelete'] ?? null,
			manageUi: $fields['manageUi'] ?? null,
			manageSettings: $fields['manageSettings'] ?? null,
			messagesAutoDeleteDelay: isset($fields['messagesAutoDeleteDelay']) ? (int)$fields['messagesAutoDeleteDelay'] : null,
			manageMessages: $fields['manageMessages'] ?? null,
			avatar: $fields['avatar'] ?? null,
			conferencePassword: $conferencePassword,
			memberEntities: $fields['memberEntities'] ?? null,
			parentChatId: $parentChatId,
			copilotMainRole: $fields['copilotMainRole'] ?? null,
		);
	}

	private static function resolveType(?string $type): string
	{
		return match ($type)
		{
			'CHANNEL' => Chat::IM_TYPE_CHANNEL,
			'COPILOT' => Chat::IM_TYPE_COPILOT,
			'COLLAB' => Chat::IM_TYPE_COLLAB,
			default => Chat::IM_TYPE_CHAT,
		};
	}

	private static function resolveEntityType(?string $entityType): ?string
	{
		return ServiceLocator::getInstance()->get(TypeRegistry::class)->getValidatedEntityType($entityType);
	}
}
