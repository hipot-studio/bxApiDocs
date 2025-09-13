<?php

namespace Bitrix\HumanResources\Controller\Structure\Node\Member;

use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Command\Structure\Node\SaveNodeChatsCommand;
use Bitrix\HumanResources\Engine\Controller;
use Bitrix\HumanResources\Integration\Im\ChatService;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\NodeMemberService;
use Bitrix\HumanResources\Exception\CommandException;
use Bitrix\HumanResources\Exception\CommandValidateException;
use Bitrix\HumanResources\Type\AccessibleItemType;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\HumanResources\Internals\Attribute;

class Chat extends Controller
{
	private readonly ChatService $chatService;
	private readonly NodeMemberService $nodeMemberService;
	public function __construct(Request $request = null)
	{
		$this->chatService = Container::getChatService();
		$this->nodeMemberService = Container::getNodeMemberService();
		parent::__construct($request);
	}

	#[Attribute\Access\LogicOr(
		new Attribute\StructureActionAccess(
			permission: StructureActionDictionary::ACTION_DEPARTMENT_CREATE,
			itemType: AccessibleItemType::NODE,
			itemParentIdRequestKey: 'parentId',
		),
		new Attribute\StructureActionAccess(
			permission: StructureActionDictionary::ACTION_TEAM_CREATE,
			itemType: AccessibleItemType::NODE,
			itemParentIdRequestKey: 'parentId',
		),
		new Attribute\StructureActionAccess(
			permission: StructureActionDictionary::ACTION_DEPARTMENT_COMMUNICATION_EDIT,
			itemType: AccessibleItemType::NODE,
			itemIdRequestKey: 'nodeId',
		),
		new Attribute\StructureActionAccess(
			permission: StructureActionDictionary::ACTION_TEAM_COMMUNICATION_EDIT,
			itemType: AccessibleItemType::NODE,
			itemIdRequestKey: 'nodeId',
		),
	)]
	public function saveChatListAction(
		Item\Node $node,
		array $createDefault = [SaveNodeChatsCommand::CHAT_INDEX => false, SaveNodeChatsCommand::CHANNEL_INDEX => false],
		array $ids = [SaveNodeChatsCommand::CHAT_INDEX => [], SaveNodeChatsCommand::CHANNEL_INDEX => []],
		array $removeIds = [],
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

		return $this->chatService->getChatsAndChannelsByNode($node);
	}
}
