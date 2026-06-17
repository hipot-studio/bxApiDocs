<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\ChatListModel;
use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\Model\UserModel;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Access\StructureAccessController;
use Bitrix\HumanResources\Command\Structure\Node\SaveNodeChatsCommand;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Type\NodeChatType;
use Bitrix\HumanResources\Type\NodeEntityType;

/**
 * Save (bind/unbind/create) communications for a node.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node\Communication::saveAction — REST analog
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Chat::saveChatListAction — ajax controller
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Chat::saveChannelListAction
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Chat::saveCollabListAction
 */
abstract class NodeSaveCommunicationsTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => InputProperty::nodeId('Identifier of the node'),
				'communicationType' => [
					'description' => 'Type of communication to manage',
					'type' => 'string',
					'enum' => ['chat', 'channel', 'collab'],
				],
				'createDefault' => [
					'description' => 'Create a new default communication of `communicationType` for this node',
					'type' => 'boolean',
					'default' => false,
				],
				'ids' => InputProperty::idList('Existing communication IDs to associate with the node'),
				'removeIds' => InputProperty::idList('Existing communication IDs to detach from the node'),
				'withChildren' => [
					'description' => 'If true, members of all descendant departments/teams are also added to '
						. 'the bound chats/channels/collabs. If false, only members of the target node are added. '
						. 'The flag affects only `createDefault` and `ids` — when unlinking via `removeIds`, '
						. 'child bindings created earlier with this flag are removed automatically.',
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

		$chatType = NodeChatType::tryFrom(strtoupper($communicationType));
		if ($chatType === null)
		{
			return "Invalid communication type. Must be 'chat', 'channel', or 'collab'.";
		}

		$chatModel = ChatListModel::createFromId((int)$args['nodeId']);
		$chatModel->setIdsArray($ids);

		$accessController = new StructureAccessController($userId);
		if (!$accessController->check($chatType->getEditActionId($this->type), $chatModel))
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
				withChildren: $withChildren,
				userId: $userId,
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
		if (!Storage::instance()->isCompanyStructureConverted())
		{
			return false;
		}

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
