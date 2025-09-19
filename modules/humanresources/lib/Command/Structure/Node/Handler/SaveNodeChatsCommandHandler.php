<?php

namespace Bitrix\HumanResources\Command\Structure\Node\Handler;

use Bitrix\HumanResources\Command\Structure\Node\SaveNodeChatsCommand;
use Bitrix\HumanResources\Integration\Im\ChatService;
use Bitrix\HumanResources\Integration\Socialnetwork\CollabService;
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
	private CollabService $collabService;
	private NodeMemberService $nodeMemberService;
	private NodeRelationService $nodeRelationService;

	public function __construct()
	{
		$this->chatService = Container::getChatService();
		$this->nodeMemberService = Container::getNodeMemberService();
		$this->nodeRelationService = Container::getNodeRelationService();
		$this->collabService = Container::getCollabService();
	}

	public function __invoke(SaveNodeChatsCommand $command): SaveNodeChatsResult
	{
		if (!empty($command->removeIds[SaveNodeChatsCommand::CHAT_INDEX])
			|| !empty($command->removeIds[SaveNodeChatsCommand::CHANNEL_INDEX])
		)
		{
			// unlink removed relations
			// no need to check permission, because we depend on _COMMUNICATION_EDIT permission
			$this->nodeRelationService->unlinkByEntityIdsAndNodeIdAndType(
				$command->node->id,
				array_merge(
					$command->removeIds[SaveNodeChatsCommand::CHAT_INDEX],
					$command->removeIds[SaveNodeChatsCommand::CHANNEL_INDEX],
				),
				RelationEntityType::CHAT,
			);
		}

		if (!empty($command->removeIds[SaveNodeChatsCommand::COLLAB_INDEX]))
		{
			// same for collabs
			$this->nodeRelationService->unlinkByEntityIdsAndNodeIdAndType(
				$command->node->id,
				$command->removeIds[SaveNodeChatsCommand::COLLAB_INDEX],
				RelationEntityType::COLLAB,
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
		$filteredCollabsIds = $this->collabService->filterByPermissions(
			$command->ids[SaveNodeChatsCommand::COLLAB_INDEX],
			CurrentUser::get()->getId(),
		);

		if ($command->createDefault[SaveNodeChatsCommand::CHAT_INDEX]
			|| $command->createDefault[SaveNodeChatsCommand::CHANNEL_INDEX]
			|| $command->createDefault[SaveNodeChatsCommand::COLLAB_INDEX]
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

			// create default collab
			if ((count($headIds) > 0) && $command->createDefault[SaveNodeChatsCommand::COLLAB_INDEX])
			{
				// ToDo: implement
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
					withChildNodes: $command->ids[SaveNodeChatsCommand::WITH_CHILDREN_INDEX] ?? false,
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
					withChildNodes: $command->ids[SaveNodeChatsCommand::WITH_CHILDREN_INDEX] ?? false,
					entitySubtype: RelationEntitySubtype::Channel,
				)
			);
		}

		foreach ($filteredCollabsIds as $collabId)
		{
			$nodeRelationCollection->add(
				new Item\NodeRelation(
					nodeId: $command->node->id,
					entityId: $collabId,
					entityType: RelationEntityType::COLLAB,
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
