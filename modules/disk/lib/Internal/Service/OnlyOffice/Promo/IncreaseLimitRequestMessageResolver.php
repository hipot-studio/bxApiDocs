<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\IncreaseLimitRequestMessageResolverInterface;
use Bitrix\HumanResources\Public\Service\Node\UserService;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use CIMMessage;

Loader::requireModule('im');
Loader::requireModule('humanresources');

class IncreaseLimitRequestMessageResolver implements IncreaseLimitRequestMessageResolverInterface
{
	private UserService $userService;
	private CurrentUser $currentUser;

	public function __construct()
	{
		$this->userService = Container::getUserService();
		$this->currentUser = CurrentUser::get();
	}

	public function resolve(): ?IncreaseLimitRequestMessageDto
	{
		$managerId = $this->resolveCurrentUserManagerId();
		if ($managerId === null)
		{
			return null;
		}

		return new IncreaseLimitRequestMessageDto(
			chatId: $this->getChatId($managerId),
			dialogId: (string)$managerId,
			buyLink: $this->getBuyLink(),
		);
	}

	private function resolveCurrentUserManagerId(): ?int
	{
		$currentUserId = (int)$this->currentUser->getId();
		$nodeMemberCollection = $this->userService->getUserHeads($currentUserId, NodeEntityType::DEPARTMENT);
		if ($nodeMemberCollection->empty())
		{
			return null;
		}

		$managerIds = $nodeMemberCollection->getUniqueEntityIds();
		$managerKey = array_rand($managerIds);

		return (int)$managerIds[$managerKey];
	}

	private function getChatId(int $managerId): int
	{
		$currentUserId = (int)$this->currentUser->getId();

		return CIMMessage::GetChatId($currentUserId, $managerId);
	}

	private function getBuyLink(): string
	{
		return (string)BoostBuyLink::monthly();
	}
}
