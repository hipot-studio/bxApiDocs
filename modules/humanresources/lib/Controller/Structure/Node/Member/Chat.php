<?php

namespace Bitrix\HumanResources\Controller\Structure\Node\Member;

use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Command\Structure\Node\SaveNodeChatsCommand;
use Bitrix\HumanResources\Engine\Controller;
use Bitrix\HumanResources\Integration\Im\ChatService;
use Bitrix\HumanResources\Integration\Socialnetwork\CollabService;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Exception\CommandException;
use Bitrix\HumanResources\Exception\CommandValidateException;
use Bitrix\HumanResources\Type\AccessibleItemType;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\HumanResources\Internals\Attribute;

class Chat extends Controller
{
	private readonly ChatService $chatService;
	private readonly CollabService $collabService;

	public function __construct(Request $request = null)
	{
		$this->chatService = Container::getChatService();
		$this->collabService = Container::getCollabService();
		parent::__construct($request);
	}

	#[Attribute\Access\LogicOr(
		new Attribute\StructureActionAccess(
			permission: StructureActionDictionary::ACTION_DEPARTMENT_COMMUNICATION_EDIT,
			itemType: AccessibleItemType::CHAT_LIST,
			itemIdRequestKey: 'nodeId',
		),
		new Attribute\StructureActionAccess(
			permission: StructureActionDictionary::ACTION_TEAM_COMMUNICATION_EDIT,
			itemType: AccessibleItemType::CHAT_LIST,
			itemIdRequestKey: 'nodeId',
		),
	)]
	public function saveChatListAction(
		Item\Node $node,
		array $createDefault = [
			SaveNodeChatsCommand::CHAT_INDEX => false,
			SaveNodeChatsCommand::CHANNEL_INDEX => false,
			SaveNodeChatsCommand::COLLAB_INDEX => false,
		],
		array $ids = [
			SaveNodeChatsCommand::CHAT_INDEX => [],
			SaveNodeChatsCommand::CHANNEL_INDEX => [],
			SaveNodeChatsCommand::COLLAB_INDEX => [],
			SaveNodeChatsCommand::WITH_CHILDREN_INDEX => false,
		],
		array $removeIds = [
			SaveNodeChatsCommand::CHAT_INDEX => [],
			SaveNodeChatsCommand::CHANNEL_INDEX => [],
			SaveNodeChatsCommand::COLLAB_INDEX => [],
		],
	): void
	{
		try
		{
			$commandResult = (new SaveNodeChatsCommand(
				$node,
				$createDefault,
				$ids,
				$removeIds,
			))->run();

			if (!$commandResult->isSuccess())
			{
				$this->addErrors($commandResult->getErrors());
			}
		}
		catch (CommandException|CommandValidateException $e)
		{
			$this->addError(new Error($e->getMessage()));
		}
	}

	public function getListAction(Item\Node $node): array
	{
		$chatService = Container::getChatService();
		if (!$chatService->isAvailable())
		{
			return [];
		}

		$result = $this->chatService->getChatsAndChannelsByNode($node);
		$result['collabs'] = $this->collabService->getCollabsByNode($node);

		return $result;
	}
}
