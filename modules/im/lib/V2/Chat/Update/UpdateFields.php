<?php

namespace Bitrix\Im\V2\Chat\Update;

use Bitrix\Im\V2\Controller\Chat\Dto\ChatUpdateFieldsDto;
use Bitrix\Im\V2\Integration\HumanResources\Structure;

class UpdateFields
{
	public function __construct(
		protected ?string $title = null,
		protected ?string $description = null,
		protected mixed $avatar = null,
		protected ?int $ownerId = null,
		protected ?string $type = null,
		protected ?string $searchable = null,
		protected ?string $manageUI = null,
		protected ?string $manageUsersAdd = null,
		protected ?string $manageUsersDelete = null,
		protected ?string $manageMessages = null,
		protected array $addedUsers = [],
		protected ?bool $hideHistory = null,
		protected array $deletedUsers = [],
		protected array $addedDepartments = [],
		protected array $deletedDepartments = [],
		protected array $addedManagers = [],
		protected array $deletedManagers = [],
		protected ?string $manageMessagesAutoDelete = null,
		protected ?int $parentChatId = null,
		protected ?string $manageGuestInvites = null,
	){}

	public static function create(array $fields): self
	{
		[$addedUsers, $addedDepartments] = Structure::splitEntities($fields['ADDED_MEMBER_ENTITIES'] ?? []);
		[$deletedUsers, $deletedDepartments] = Structure::splitEntities($fields['DELETED_MEMBER_ENTITIES'] ?? []);

		return new self(
			$fields['TITLE'] ?? null,
			$fields['DESCRIPTION'] ?? null,
			$fields['AVATAR'] ?? null,
			isset($fields['OWNER_ID']) ? (int)$fields['OWNER_ID'] : null,
			$fields['TYPE'] ?? null,
			$fields['SEARCHABLE'] ?? null,
			$fields['MANAGE_UI'] ?? null,
			$fields['MANAGE_USERS_ADD'] ?? null,
			$fields['MANAGE_USERS_DELETE'] ?? null,
			$fields['MANAGE_MESSAGES'] ?? null,
			$addedUsers ?? [],
			self::prepareBool($fields['HIDE_HISTORY'] ?? null),
			$deletedUsers ?? [],
			$addedDepartments ?? [],
			$deletedDepartments ?? [],
			self::prepareArrayField($fields['ADDED_MANAGERS'] ?? []),
			self::prepareArrayField($fields['DELETED_MANAGERS'] ?? []),
			$fields['MANAGE_MESSAGES_AUTO_DELETE'] ?? null,
			null,
			$fields['MANAGE_GUEST_INVITES'] ?? null,
		);
	}

	public static function fromDto(ChatUpdateFieldsDto $dto): self
	{
		return self::create([
			'TITLE' => $dto->title,
			'DESCRIPTION' => $dto->description,
			'AVATAR' => $dto->avatar,
			'OWNER_ID' => $dto->ownerId,
			'TYPE' => $dto->type,
			'SEARCHABLE' => $dto->searchable,
			'MANAGE_UI' => $dto->manageUi,
			'MANAGE_USERS_ADD' => $dto->manageUsersAdd,
			'MANAGE_USERS_DELETE' => $dto->manageUsersDelete,
			'MANAGE_MESSAGES' => $dto->manageMessages,
			'MANAGE_MESSAGES_AUTO_DELETE' => $dto->manageMessagesAutoDelete,
			'ADDED_MEMBER_ENTITIES' => $dto->addedMemberEntities ?? [],
			'DELETED_MEMBER_ENTITIES' => $dto->deletedMemberEntities ?? [],
			'HIDE_HISTORY' => $dto->hideHistory,
			'ADDED_MANAGERS' => $dto->addedManagers ?? [],
			'DELETED_MANAGERS' => $dto->deletedManagers ?? [],
			'MANAGE_GUEST_INVITES' => $dto->manageGuestInvites,
		]);
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function getSearchable(): ?string
	{
		return $this->searchable;
	}

	public function getAddedUsers(): array
	{
		return $this->addedUsers;
	}

	public function getDeletedUsers(): array
	{
		return $this->deletedUsers;
	}

	public function shouldHideHistory(): ?bool
	{
		return $this->hideHistory;
	}

	public function getDeletedDepartments(): array
	{
		return $this->deletedDepartments;
	}

	public function getAddedDepartments(): array
	{
		return $this->addedDepartments;
	}

	public function getAddedManagers(): array
	{
		return $this->addedManagers;
	}

	public function getDeletedManagers(): array
	{
		return $this->deletedManagers;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function getOwnerId(): ?int
	{
		return $this->ownerId;
	}

	public function getAvatar(): mixed
	{
		return $this->avatar;
	}

	public function getParentChatId(): ?int
	{
		return $this->parentChatId;
	}

	protected static function prepareArrayField(array $array): array
	{
		$result = [];
		foreach ($array as $item)
		{
			if (is_numeric($item) && (int)$item > 0)
			{
				$result[] = (int)$item;
			}
		}

		return $result;
	}

	protected static function prepareBool(?string $value, ?bool $default = null): ?bool
	{
		if ($value === 'Y')
		{
			return true;
		}

		if ($value === 'N')
		{
			return false;
		}

		return $default;
	}

	public function getArrayToSave(): array
	{
		$array = [
			'TITLE' => $this->title,
			'DESCRIPTION' => $this->description,
			'AUTHOR_ID' => $this->ownerId,
			'MANAGE_UI' => $this->manageUI,
			'MANAGE_USERS_ADD' => $this->manageUsersAdd,
			'MANAGE_USERS_DELETE' => $this->manageUsersDelete,
			'MANAGE_MESSAGES' => $this->manageMessages,
			'MANAGE_MESSAGES_AUTO_DELETE' => $this->manageMessagesAutoDelete,
			'PARENT_ID' => $this->parentChatId,
			'MANAGE_GUEST_INVITES' => $this->manageGuestInvites,
		];
		return array_filter($array, function ($value) {
			return $value !== null;
		});
	}
}
