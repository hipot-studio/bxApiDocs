<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\Model\UserModel;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Command\Structure\Node\SaveNodeChatsCommand;
use Bitrix\HumanResources\Exception\CommandException;
use Bitrix\HumanResources\Exception\CommandValidateException;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Type\NodeChatType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Error;

abstract class NodeSaveCommunicationsTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => [
					'description' => 'Identifier of the node',
					'type' => 'number',
				],
				'communicationType' => [
					'description' => 'Type of communication to manage (chat, channel, collab)',
					'type' => 'string',
					'enum' => ['chat', 'channel', 'collab'],
				],
				'createDefault' => [
					'description' => 'Whether to create a default communication',
					'type' => 'boolean',
					'default' => false,
				],
				'ids' => [
					'description' => 'Array of communication IDs to add the node to',
					'type' => 'array',
					'items' => [
						'type' => 'number',
					],
					'default' => [],
				],
				'removeIds' => [
					'description' => 'Array of communication IDs to remove the node from',
					'type' => 'array',
					'items' => [
						'type' => 'number',
					],
					'default' => [],
				],
				'withChildren' => [
					'description' => 'Whether to apply changes to child nodes',
					'type' => 'boolean',
					'default' => false,
				],
			],
			'additionalProperties' => false,
			'required' => ['nodeId', 'communicationType'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$item = NodeModel::createFromId((int)$args['nodeId']);

		$communicationType = $args['communicationType'] ?? '';
		$createDefault = $args['createDefault'] ?? false;
		$ids = $args['ids'] ?? [];
		$removeIds = $args['removeIds'] ?? [];
		$withChildren = $args['withChildren'] ?? false;

		switch ($communicationType) {
			case 'chat':
				$actionPermission = $this->type === NodeEntityType::DEPARTMENT
					? StructureActionDictionary::ACTION_DEPARTMENT_CHAT_EDIT
					: StructureActionDictionary::ACTION_TEAM_CHAT_EDIT
				;
				$chatType = NodeChatType::Chat;
				break;
			case 'channel':
				$actionPermission = $this->type === NodeEntityType::DEPARTMENT
					? StructureActionDictionary::ACTION_DEPARTMENT_CHANNEL_EDIT
					: StructureActionDictionary::ACTION_TEAM_CHANNEL_EDIT
				;
				$chatType = NodeChatType::Channel;
				break;
			case 'collab':
				$actionPermission = $this->type === NodeEntityType::DEPARTMENT
					? StructureActionDictionary::ACTION_DEPARTMENT_COLLAB_EDIT
					: StructureActionDictionary::ACTION_TEAM_COLLAB_EDIT
				;
				$chatType = NodeChatType::Collab;
				break;
			default:
				return "Invalid communication type. Must be 'chat', 'channel', or 'collab'.";
		}

		if (!$this->checkAccess($userId, $actionPermission, $item))
		{
			return 'Access denied';
		}

		$node = $item->getNode();

		try
		{
			$commandResult = (new SaveNodeChatsCommand(
				node: $node,
				chatType: $chatType,
				createDefault: $createDefault,
				ids: $ids,
				removeIds: $removeIds,
				withChildren: $withChildren
			))->run();

			if (!$commandResult->isSuccess())
			{
				$errors = [];
				foreach ($commandResult->getErrors() as $error)
				{
					$errors[] = $error->getMessage();
				}

				return 'Error updating node communications: ' . implode(', ', $errors);
			}

			$actionDescription = [];
			if ($createDefault) {
				$actionDescription[] = 'created default';
			}
			if (!empty($ids)) {
				$actionDescription[] = 'added to existing';
			}
			if (!empty($removeIds)) {
				$actionDescription[] = 'removed from some';
			}

			$actionText = !empty($actionDescription) ? implode(', ', $actionDescription) : 'updated';

			return "Node {$communicationType}s {$actionText} successfully" .
				($withChildren ? ' (including child nodes)' : '');
		}
		catch (\Exception $e)
		{
			$this->logException('Error updating node communications: ' . $e->getMessage());

			return 'Error updating node communications.';
		}
	}

	public function canList(int $userId): bool
	{
		$user = UserModel::createFromId($userId);

		if ($user->isAdmin())
		{
			return true;
		}

		// Check for any of the needed permissions
		if ($this->type === NodeEntityType::DEPARTMENT)
		{
			$chatEditPermission = $user->getPermission(PermissionDictionary::HUMAN_RESOURCES_DEPARTMENT_CHAT_EDIT);
			$channelEditPermission = $user->getPermission(PermissionDictionary::HUMAN_RESOURCES_DEPARTMENT_CHANNEL_EDIT);
			$collabEditPermission = $user->getPermission(PermissionDictionary::HUMAN_RESOURCES_DEPARTMENT_COLLAB_EDIT);

			$bannedValues = [
				PermissionVariablesDictionary::VARIABLE_NONE,
				PermissionVariablesDictionary::VARIABLE_SELF_TEAMS,
				PermissionVariablesDictionary::VARIABLE_SELF_TEAMS_SUB_TEAMS,
			];
		}
		else
		{
			$chatEditPermission = $user->getPermission(PermissionDictionary::HUMAN_RESOURCES_TEAM_CHAT_EDIT);
			$channelEditPermission = $user->getPermission(PermissionDictionary::HUMAN_RESOURCES_TEAM_CHANNEL_EDIT);
			$collabEditPermission = $user->getPermission(PermissionDictionary::HUMAN_RESOURCES_TEAM_COLLAB_EDIT);

			$bannedValues = [ PermissionVariablesDictionary::VARIABLE_NONE ];
		}

		$permissions = [ $chatEditPermission, $channelEditPermission, $collabEditPermission ];

		// If all permissions are NONE, no access
		if (count(array_filter($permissions, function($permission) use ($bannedValues) {
			return !in_array($permission, $bannedValues);
		})) === 0) {
			return false;
		}

		return true;
	}
}
