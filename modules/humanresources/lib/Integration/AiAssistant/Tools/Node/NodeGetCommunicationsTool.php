<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

abstract class NodeGetCommunicationsTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => [
					'description' => 'Identifier of the node to get list of communications for',
					'type' => 'number',
				],
			],
			'additionalProperties' => false,
			'required' => ['nodeId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$item = NodeModel::createFromId((int)$args['nodeId']);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_STRUCTURE_VIEW
			: StructureActionDictionary::ACTION_TEAM_VIEW
		;
		if (!$this->checkAccess($userId, $actionId, $item))
		{
			return 'Access denied';
		}

		$node = $item->getNode();

		try
		{

			$chatService = Container::getChatService();
			if (!$chatService->isAvailable())
			{
				return 'Communications are not available';
			}

			$chatsResult = Container::getChatService()->getChatsAndChannelsByNode($node, $userId);
			$collabsResult = Container::getCollabService()->getCollabsByNode($node, $userId);

			$data = array_merge($chatsResult, $collabsResult);

			$result = '';

			// Format chats information
			if (!empty($data['chats']))
			{
				$result .= "The node has chats: \n";
				foreach ($data['chats'] as $chat)
				{
					$result .= "- ID: {$chat['id']}, Name: {$chat['title']}, Dialog ID: {$chat['dialogId']}\n";
				}
			}
			else
			{
				$result .= "The node has no chats.\n";
			}

			// Format channels information
			if (!empty($data['channels']))
			{
				$result .= "\nThe node has channels: \n";
				foreach ($data['channels'] as $channel)
				{
					$result .= "- ID: {$channel['id']}, Name: {$channel['title']}, Dialog ID: {$channel['dialogId']}\n";
				}
			}
			else
			{
				$result .= "\nThe node has no channels.\n";
			}

			// Format collabs information
			if (!empty($data['collabs']))
			{
				$result .= "\nThe node has collabs: \n";
				foreach ($data['collabs'] as $collab)
				{
					$result .= "- ID: {$collab['id']}, Name: {$collab['title']}, Dialog ID: {$collab['dialogId']}\n";
				}
			}
			else
			{
				$result .= "\nThe node has no collabs.\n";
			}

			// Add information about items without access
			$result .= "\nItems without access:";
			$result .= "\n- Chats: " . ($data['chatsNoAccess'] ?? 0);
			$result .= "\n- Channels: " . ($data['channelsNoAccess'] ?? 0);
			$result .= "\n- Collabs: " . ($data['collabsNoAccess'] ?? 0);

			return $result;
		}
		catch (\Exception $e)
		{
			$this->logException('Error getting communications: ' . $e->getMessage());

			return 'Error getting communications.';
		}
	}
}
