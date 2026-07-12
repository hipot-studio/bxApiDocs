<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Dto;

final class ChatUpdateFieldsDto
{
	private function __construct(
		public readonly ?string $title,
		public readonly ?string $description,
		public readonly mixed $avatar,
		public readonly ?int $ownerId,
		public readonly ?string $type,
		public readonly ?string $searchable,
		public readonly ?string $manageUi,
		public readonly ?string $manageUsersAdd,
		public readonly ?string $manageUsersDelete,
		public readonly ?string $manageMessages,
		public readonly ?string $manageMessagesAutoDelete,
		public readonly ?array $addedMemberEntities,
		public readonly ?array $deletedMemberEntities,
		public readonly ?string $hideHistory,
		public readonly ?array $addedManagers,
		public readonly ?array $deletedManagers,
	)
	{
	}

	public static function create(array $fields): self
	{
		return new self(
			title: $fields['title'] ?? null,
			description: $fields['description'] ?? null,
			avatar: $fields['avatar'] ?? null,
			ownerId: isset($fields['ownerId']) ? (int)$fields['ownerId'] : null,
			type: $fields['type'] ?? null,
			searchable: $fields['searchable'] ?? null,
			manageUi: $fields['manageUi'] ?? null,
			manageUsersAdd: $fields['manageUsersAdd'] ?? null,
			manageUsersDelete: $fields['manageUsersDelete'] ?? null,
			manageMessages: $fields['manageMessages'] ?? null,
			manageMessagesAutoDelete: $fields['manageMessagesAutoDelete'] ?? null,
			addedMemberEntities: $fields['addedMemberEntities'] ?? null,
			deletedMemberEntities: $fields['deletedMemberEntities'] ?? null,
			hideHistory: $fields['hideHistory'] ?? null,
			addedManagers: $fields['addedManagers'] ?? null,
			deletedManagers: $fields['deletedManagers'] ?? null,
		);
	}
}
