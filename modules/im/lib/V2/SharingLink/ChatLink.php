<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

use Bitrix\Im\V2\AccessCheckable;
use Bitrix\Im\V2\Permission\ChatActionAccessCheckable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;

class ChatLink extends SharingLink implements ChatActionAccessCheckable
{
	private const GET_PARAM = 'IM_CODE';

	public static function getEntityType(): LinkEntityType
	{
		return LinkEntityType::Chat;
	}

	protected function getUrl(): string
	{
		$path = '/online/';
		$getParam = '?' . self::GET_PARAM . '=';

		return \Bitrix\Im\Common::getPublicDomain() . $path . $getParam . $this->getCode();
	}

	protected function getChat(): Chat
	{
		return Chat::getInstance((int)$this->getEntityId());
	}

	public function getRecipientsForPull(EventType $type): array
	{
		return $this->getUsersWithAccess();
	}

	/**
	 * @return array<int, int>
	 */
	protected function getUsersWithAccess(): array
	{
		return match ($this->getType())
		{
			Type::Individual => [$this->getAuthorId() => $this->getAuthorId()],
			Type::Primary, Type::Custom => $this->getLinkManagers(),
		};
	}

	/**
	 * @return array<int, int>
	 */
	protected function getLinkManagers(): array
	{
		$chat = $this->getChat();
		$requiredRole = Permission::getRoleForActionByType($chat->getExtendedType(false), Action::ManageSharingLinks);

		return
			$chat
				->getRelationFacade()
				?->get()
				->filter(fn (Relation $relation) => Permission::compareRole($relation->getRole(), $requiredRole))
				->getUserIds()
		;
	}

	public function getEntity(): Chat
	{
		return $this->getChat();
	}

	public function canDo(Action $action, mixed $target = null): bool
	{
		if ($action === Action::UpdateSharingLink)
		{
			$userId = Locator::getContext()->getUserId();

			return in_array($userId, $this->getUsersWithAccess(), true);
		}

		return $this->getChat()->canDo($action, $target);
	}

	public function apply(int $userId): Result
	{
		$this->getChat()->joinBySharingLink($this, $userId);

		return new Result();
	}
}
