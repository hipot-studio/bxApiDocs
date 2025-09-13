<?php

namespace Bitrix\HumanResources\Command\Structure\Node\Handler;

use Bitrix\HumanResources\Command\Structure\Node\SaveNodeChatsCommand;
use Bitrix\HumanResources\Integration\Im\ChatService;
use Bitrix\HumanResources\Result\Command\Structure\SaveNodeChatsResult;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Contract\Service\NodeMemberService;
use Bitrix\HumanResources\Service\NodeRelationService;
use Bitrix\HumanResources\Type\RelationEntitySubtype;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\HumanResources\Item;
use Bitrix\Main\Engine\CurrentUser;

class SaveNodeChatsCommandHandler
{
	private ChatService $chatService;
	private NodeMemberService $nodeMemberService;
	private NodeRelationService $nodeRelationService;

	public function __construct()
	{
		$this->chatService = Container::getChatService();
		$this->nodeMemberService = Container::getNodeMemberService();
		$this->nodeRelationService = Container::getNodeRelationService();
	}

	public function __invoke(SaveNodeChatsCommand $command): SaveNodeChatsResult
	{
		if (!empty($command->removeIds))
		{
			// unlink removed relations
			// no need to check permission, because we depend on _COMMUNICATION_EDIT permission
			$this->nodeRelationService->unlinkByEntityIdsAndNodeIdAndType(
				$command->node->id,
				$command->removeIds,
				RelationEntityType::CHAT,
			);
		}

		/**
		 * We filter chats & channels before creating new because current user
		 * might have no right to add department to a newly created chat or channel
		 */
		$filteredChatIds = $this->chatService
			->filterByPermissionsAndType(
				$command->ids[SaveNodeChatsCommand::CHAT_INDEX],
				CurrentUser::get()->getId(),
				RelationEntitySubtype::Chat)
			->getChatIds()
		;
		$filteredChannelsIds = $this->chatService
			->filterByPermissionsAndType(
				$command->ids[SaveNodeChatsCommand::CHANNEL_INDEX],
				CurrentUser::get()->getId(),
				RelationEntitySubtype::Channel)
			->getChatIds()
		;

		if ($command->createDefault[SaveNodeChatsCommand::CHAT_INDEX]
			|| $command->createDefault[SaveNodeChatsCommand::CHANNEL_INDEX]
		)
		{
			$heads = $this->nodeMemberService->getDefaultHeadRoleEmployees($command->node->id);
			$headIds = $heads->map(function ($head) { return $head->entityId; });

			// create default chat
			if ((count($headIds) > 0) && $command->createDefault[SaveNodeChatsCommand::CHAT_INDEX])
			{
				$addResult = $this->chatService->createChat($command->node, $headIds, RelationEntitySubtype::Chat);
				if ($addResult->isSuccess())
				{
					$filteredChatIds[] = $addResult->getChatId();
				}
			}

			// create default channel
			if ((count($headIds) > 0) && $command->createDefault[SaveNodeChatsCommand::CHANNEL_INDEX])
			{
				$addResult = $this->chatService->createChat($command->node, $headIds, RelationEntitySubtype::Channel);
				if ($addResult->isSuccess())
				{
					$filteredChannelsIds[] = $addResult->getChatId();
				}
			}
		}

		$nodeRelationCollection = new Item\Collection\NodeRelationCollection();
		foreach ($filteredChatIds as $chatId)
		{
			$nodeRelationCollection->add(
				new Item\NodeRelation(
					nodeId: $command->node->id,
					entityId: $chatId,
					entityType: RelationEntityType::CHAT,
					entitySubtype: RelationEntitySubtype::Chat,
				)
			);
		}

		foreach ($filteredChannelsIds as $channelId)
		{
			$nodeRelationCollection->add(
				new Item\NodeRelation(
					nodeId: $command->node->id,
					entityId: $channelId,
					entityType: RelationEntityType::CHAT,
					entitySubtype: RelationEntitySubtype::Channel,
				)
			);
		}

		if (!$nodeRelationCollection->empty())
		{
			$this->nodeRelationService->linkNodeRelationCollection($nodeRelationCollection);
		}

		return new SaveNodeChatsResult();
	}
}
