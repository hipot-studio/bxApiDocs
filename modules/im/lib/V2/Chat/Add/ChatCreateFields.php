<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Add;

use Bitrix\Im\V2\AccessCheckable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Controller\Chat\Dto\ChatCreateFieldsDto;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Permission\GlobalAction;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Engine\CurrentUser;

class ChatCreateFields implements AccessCheckable
{
	public function __construct(
		protected string $type = Chat::IM_TYPE_CHAT,
		protected ?string $entityType = null,
		protected ?string $entityId = null,
		protected ?string $entityData1 = null,
		protected ?string $entityData2 = null,
		protected ?string $entityData3 = null,
		protected ?string $title = null,
		protected ?string $description = null,
		protected ?string $searchable = null,
		protected ?string $color = null,
		protected ?int $authorId = null,
		protected ?array $users = null,
		protected ?array $managers = null,
		protected ?string $manageUsersAdd = null,
		protected ?string $manageUsersDelete = null,
		protected ?string $manageUi = null,
		protected ?string $manageSettings = null,
		protected ?int $messagesAutoDeleteDelay = null,
		protected ?string $manageMessages = null,
		protected mixed $avatar = null,
		protected ?string $conferencePassword = null,
		protected ?array $memberEntities = null,
		protected ?Chat $parentChat = null,
		protected ?array $chatParams = null,
	)
	{
	}

	public static function fromDto(ChatCreateFieldsDto $dto): self
	{
		$parentChat = null;
		if ($dto->parentChatId !== null)
		{
			$parentChat = Chat::getInstance($dto->parentChatId);
		}

		return new self(
			type: $dto->type,
			entityType: $dto->entityType,
			entityId: $dto->entityId,
			entityData1: $dto->entityData1,
			entityData2: $dto->entityData2,
			entityData3: $dto->entityData3,
			title: $dto->title,
			description: $dto->description,
			searchable: $dto->searchable,
			color: $dto->color,
			authorId: $dto->ownerId,
			users: $dto->users,
			managers: $dto->managers,
			manageUsersAdd: $dto->manageUsersAdd,
			manageUsersDelete: $dto->manageUsersDelete,
			manageUi: $dto->manageUi,
			manageSettings: $dto->manageSettings,
			messagesAutoDeleteDelay: $dto->messagesAutoDeleteDelay,
			manageMessages: $dto->manageMessages,
			avatar: $dto->avatar,
			conferencePassword: $dto->conferencePassword,
			memberEntities: $dto->memberEntities,
			parentChat: $parentChat,
			chatParams: self::buildChatParamsFromDto($dto),
		);
	}

	public function checkAccess(?int $userId = null): Result
	{
		$result = new Result();
		$userId = $userId ?? (int)CurrentUser::get()->getId();

		$target = ['TYPE' => $this->type, 'ENTITY_TYPE' => $this->entityType];
		if (!Permission::canDoGlobalAction($userId, GlobalAction::CreateChat, $target))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		if ($this->parentChat !== null && !$this->parentChat->getChatId())
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		if ($this->parentChat !== null && !$this->parentChat->canDo(Action::CreateChildChat))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		return $result;
	}

	public function toArray(): array
	{
		return array_filter([
			'TYPE' => $this->type,
			'ENTITY_TYPE' => $this->entityType,
			'ENTITY_ID' => $this->entityId,
			'ENTITY_DATA_1' => $this->entityData1,
			'ENTITY_DATA_2' => $this->entityData2,
			'ENTITY_DATA_3' => $this->entityData3,
			'TITLE' => $this->title,
			'DESCRIPTION' => $this->description,
			'SEARCHABLE' => $this->searchable,
			'COLOR' => $this->color,
			'AUTHOR_ID' => $this->authorId,
			'USERS' => $this->users,
			'MANAGERS' => $this->managers,
			'MANAGE_USERS_ADD' => $this->manageUsersAdd,
			'MANAGE_USERS_DELETE' => $this->manageUsersDelete,
			'MANAGE_UI' => $this->manageUi,
			'MANAGE_SETTINGS' => $this->manageSettings,
			'MESSAGES_AUTO_DELETE_DELAY' => $this->messagesAutoDeleteDelay,
			'MANAGE_MESSAGES' => $this->manageMessages,
			'AVATAR' => $this->avatar,
			'CONFERENCE_PASSWORD' => $this->conferencePassword,
			'MEMBER_ENTITIES' => $this->memberEntities,
			'PARENT_CHAT' => $this->parentChat,
			'CHAT_PARAMS' => $this->chatParams,
		], fn($v) => $v !== null);
	}

	public function getAvatar(): mixed
	{
		return $this->avatar;
	}

	private static function buildChatParamsFromDto(ChatCreateFieldsDto $dto): ?array
	{
		$params = [];

		if ($dto->copilotMainRole !== null)
		{
			$params[] = [
				'PARAM_NAME' => Chat\Param\Params::COPILOT_MAIN_ROLE,
				'PARAM_VALUE' => (new RoleManager())->getValidRoleCode($dto->copilotMainRole),
			];
		}

		return $params === [] ? null : $params;
	}
}
