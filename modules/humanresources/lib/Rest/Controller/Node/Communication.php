<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Controller\Node;

use Bitrix\HumanResources\Access\Model\ChatListModel;
use Bitrix\HumanResources\Rest\Trait\NodeControllerTrait;
use Bitrix\HumanResources\Command\Structure\Node\SaveNodeChatsCommand;
use Bitrix\HumanResources\Exception\CommandException;
use Bitrix\HumanResources\Exception\CommandValidateException;
use Bitrix\HumanResources\Item\Node as NodeItem;
use Bitrix\HumanResources\Rest\Dto\NodeDto;
use Bitrix\HumanResources\Rest\RequestParams;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeChatType;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Node communication operations: list and save chats, channels, collabs.
 *
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Chat — existing ajax controller
 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeGetCommunicationsTool
 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeSaveCommunicationsTool
 */
#[DtoType(NodeDto::class)]
class Communication extends RestController
{
	use NodeControllerTrait;

	protected function init(): void
	{
		parent::init();
		$this->initNodeContext();
	}

	/**
	 * Get chats, channels and collabs linked to a node.
	 *
	 * @restMethod humanresources.node.communication.list
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Chat::getListAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeGetCommunicationsTool
	 */
	public function listAction(GetRequest $request): ArrayResponse
	{
		$nodeId = (int)$request->id;

		$node = $this->requireNodeById($nodeId);

		$this->checkNodeViewAccess($node);

		$chatService = Container::getChatService();
		if (!$chatService->isAvailable())
		{
			return new ArrayResponse([
				'chats' => [],
				'channels' => [],
				'collabs' => [],
			]);
		}

		$chatsResult = $chatService->getChatsAndChannelsByNode($node, $this->userId);
		$collabsResult = Container::getCollabService()->getCollabsByNode($node, $this->userId);

		return new ArrayResponse([
			'chats' => $chatsResult['chats'] ?? [],
			'channels' => $chatsResult['channels'] ?? [],
			'collabs' => $collabsResult['collabs'] ?? [],
			'chatsNoAccess' => $chatsResult['chatsNoAccess'] ?? 0,
			'channelsNoAccess' => $chatsResult['channelsNoAccess'] ?? 0,
			'collabsNoAccess' => $collabsResult['collabsNoAccess'] ?? 0,
		]);
	}

	/**
	 * Save (bind/unbind/create) communications for a node.
	 *
	 * @restMethod humanresources.node.communication.edit
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Chat::saveChatListAction
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Chat::saveChannelListAction
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Chat::saveCollabListAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeSaveCommunicationsTool
	 *
	 * Request params:
	 * - int    nodeId             Node identifier (required)
	 * - string communicationType  Type: "chat", "channel", or "collab" (required)
	 * - bool   createDefault      Create a default communication for the node (default: false)
	 * - int[]  ids                Communication IDs to bind to the node (default: [])
	 * - int[]  removeIds          Communication IDs to unbind from the node (default: [])
	 * - bool   withChildren       Apply changes to child nodes as well (default: false)
	 */
	public function editAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		$nodeId = $params->requireInt('nodeId');
		$chatType = $params->requireEnum('communicationType', NodeChatType::class, caseInsensitive: true);

		$node = $this->requireNodeById($nodeId);

		$ids = $params->getArray('ids');
		$removeIds = $params->getArray('removeIds');
		$createDefault = $params->getBool('createDefault');
		$withChildren = $params->getBool('withChildren');

		$this->checkCommunicationEditAccess($node, $chatType, $ids);

		try
		{
			$commandResult = (new SaveNodeChatsCommand(
				node: $node,
				chatType: $chatType,
				createDefault: $createDefault,
				ids: $ids,
				removeIds: $removeIds,
				withChildren: $withChildren,
			))->run();
		}
		catch (CommandValidateException $e)
		{
			throw new RequestValidationException($e->getValidationErrors());
		}
		catch (CommandException $e)
		{
			throw new RequestValidationException([
				new Error($e->getMessage()),
			]);
		}

		if (!$commandResult->isSuccess())
		{
			throw new RequestValidationException($commandResult->getErrors());
		}

		return new ArrayResponse(['success' => true]);
	}

	private function checkCommunicationEditAccess(NodeItem $node, NodeChatType $chatType, array $ids = []): void
	{
		$chatModel = ChatListModel::createFromId($node->id);
		$chatModel->setIdsArray($ids);

		if (!$this->accessController->check($chatType->getEditActionId($node->type), $chatModel))
		{
			throw new AccessDeniedException();
		}
	}
}
