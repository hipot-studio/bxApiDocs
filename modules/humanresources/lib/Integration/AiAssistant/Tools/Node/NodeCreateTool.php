<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Command\Structure\Node\CreateNodeCommand;
use Bitrix\HumanResources\Command\Structure\Node\Enum\UserAddStrategy;
use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Exception\CommandException;
use Bitrix\HumanResources\Exception\CommandValidateException;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Util\StructureHelper;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Create a new node (department or team).
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node::addAction — REST analog
 * @see \Bitrix\HumanResources\Controller\Structure\Node::addAction — ajax controller
 * @see \Bitrix\HumanResources\Controller\Structure\Department::createAction
 * @see \Bitrix\HumanResources\Controller\Structure\Team::createAction
 */
abstract class NodeCreateTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		$properties = [
			'name' => [
				'description' => 'Node name',
				'type' => 'string',
				'minLength' => 1,
			],
			'parentId' => [
				'description' => 'Identifier of the parent node',
				'type' => 'integer',
				'minimum' => 1,
			],
			'description' => [
				'description' => 'Node description',
				'type' => 'string',
			],
		];

		if ($this->type === NodeEntityType::TEAM)
		{
			$properties['colorName'] = InputProperty::colorName();
		}

		$properties['userIds'] = InputProperty::userIdsByRole($this->type) + ['default' => []];

		if ($this->type === NodeEntityType::DEPARTMENT)
		{
			$properties['moveUsersToNode'] = [
				'description' => 'If true, users in `userIds` are moved from their current departments to this one. '
					. 'If false, they are added without removing the previous membership.',
				'type' => 'boolean',
				'default' => false,
			];
		}

		$properties['createChat'] = [
			'description' => 'Whether to create a default chat for the node',
			'type' => 'boolean',
			'default' => false,
		];
		$properties['bindingChatIds'] = InputProperty::idList('Array of existing chat IDs to associate with the node');
		$properties['createChannel'] = [
			'description' => 'Whether to create a default channel for the node',
			'type' => 'boolean',
			'default' => false,
		];
		$properties['bindingChannelIds'] = InputProperty::idList('Array of existing channel IDs to associate with the node');
		$properties['createCollab'] = [
			'description' => 'Whether to create a default collab for the node',
			'type' => 'boolean',
			'default' => false,
		];
		$properties['bindingCollabIds'] = InputProperty::idList('Array of existing collab IDs to associate with the node');
		$properties['settings'] = InputProperty::nodeSettings();

		return [
			'type' => 'object',
			'properties' => $properties,
			'additionalProperties' => false,
			'required' => ['name', 'parentId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$parentId = (int)$args['parentId'];
		$parentItem = NodeModel::createFromId($parentId);
		$parentItem->setTargetNodeId($parentId);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_DEPARTMENT_CREATE
			: StructureActionDictionary::ACTION_TEAM_CREATE
		;
		if (!$this->checkAccess($userId, $actionId, $parentItem))
		{
			return 'Access denied';
		}

		$structure = StructureHelper::getDefaultStructure();
		if ($structure === null)
		{
			return 'Default structure not found.';
		}

		$structureId = $structure->id;
		$name = $args['name'];
		$parentId = (int)$args['parentId'];
		$description = $args['description'] ?? null;
		$colorName = $args['colorName'] ?? null;
		$userIds = $args['userIds'] ?? [];
		$moveUsersToNode = $args['moveUsersToNode'] ?? false;
		$createChat = $args['createChat'] ?? false;
		$bindingChatIds = $args['bindingChatIds'] ?? [];
		$createChannel = $args['createChannel'] ?? false;
		$bindingChannelIds = $args['bindingChannelIds'] ?? [];
		$createCollab = $args['createCollab'] ?? false;
		$bindingCollabIds = $args['bindingCollabIds'] ?? [];
		$settings = $args['settings'] ?? [];

		$usersStrategy = UserAddStrategy::SaveUsersStrategy;

		if ($moveUsersToNode)
		{
			$usersStrategy = UserAddStrategy::MoveUsersStrategy;
		}

		$areCollabsAvailable = Feature::instance()->isCollabsAvailable();

		try
		{
			$validation = ServiceLocator::getInstance()->get('main.validation.service');

			$command = new CreateNodeCommand(
				$structureId,
				$name,
				$this->type,
				$parentId,
				$description,
				$colorName,
				$usersStrategy,
				$userIds,
				$createChat,
				$bindingChatIds,
				$createChannel,
				$bindingChannelIds,
				$areCollabsAvailable ? $createCollab : false,
				$areCollabsAvailable ? $bindingCollabIds : [],
				$settings,
			);

			$validationResult = $validation->validate($command);

			if (!$validationResult->isSuccess())
			{
				$errors = [];
				foreach ($validationResult->getErrors() as $error)
				{
					$errors[] = $error->getMessage();
				}

				$this->logException('Error getting info: ' . implode(', ', $errors));

				return 'Validation error.';
			}

			$commandResult = $command->run();

			if (!$commandResult->isSuccess())
			{
				$errors = [];
				foreach ($commandResult->getErrors() as $error)
				{
					$errors[] = $error->getMessage();
				}

				$this->logException('Error creating node: ' . implode(', ', $errors));

				return 'Error creating node.';
			}

			$nodeId = $commandResult->node?->id ?? null;

			return 'Node successfully created with ID: ' . $nodeId;
		}
		catch (CommandValidateException $e)
		{
			$this->logException('Validation error: ' . $e->getMessage());

			return 'Validation error.';
		}
		catch (CommandException $e)
		{
			$this->logException('Command error: ' . $e->getMessage());

			return 'Command error.';
		}
		catch (\Exception $e)
		{
			$this->logException('Error creating node: ' . $e->getMessage());

			return 'Error creating node.';
		}
	}
}
