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
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\DI\ServiceLocator;

abstract class NodeCreateTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'structureId' => [
					'description' => 'Identifier of the structure',
					'type' => 'number',
				],
				'name' => [
					'description' => 'Node name',
					'type' => 'string',
				],
				'parentId' => [
					'description' => 'Identifier of the parent node',
					'type' => 'number',
				],
				'description' => [
					'description' => 'Node description',
					'type' => 'string',
				],
				'colorName' => [
					'description' => 'Color name for the node. Use only if creating a team',
					'type' => 'string',
				],
				'userIds' => [
					'description' => 'Array of user IDs to add to the node',
					'type' => 'array',
					'items' => [
						'type' => 'number',
					],
					'default' => [],
				],
				'moveUsersToNode' => [
					'description' => 'Whether to move users to the node or just save them',
					'type' => 'boolean',
					'default' => false,
				],
				'createChat' => [
					'description' => 'Whether to create a default chat for the node',
					'type' => 'boolean',
					'default' => false,
				],
				'bindingChatIds' => [
					'description' => 'Array of chat IDs to associate with the node',
					'type' => 'array',
					'items' => [
						'type' => 'number',
					],
					'default' => [],
				],
				'createChannel' => [
					'description' => 'Whether to create a default channel for the node',
					'type' => 'boolean',
					'default' => false,
				],
				'bindingChannelIds' => [
					'description' => 'Array of channel IDs to associate with the node',
					'type' => 'array',
					'items' => [
						'type' => 'number',
					],
					'default' => [],
				],
				'createCollab' => [
					'description' => 'Whether to create a default collab for the node',
					'type' => 'boolean',
					'default' => false,
				],
				'bindingCollabIds' => [
					'description' => 'Array of collab IDs to associate with the node',
					'type' => 'array',
					'items' => [
						'type' => 'number',
					],
					'default' => [],
				],
				'settings' => [
					'description' => 'Associative array with NodeSettingsType as keys (e.g., "BUSINESS_PROC_AUTHORITY", "REPORTS_AUTHORITY") and arrays of NodeSettingsAuthorityType as values (e.g., ["HEAD", "DEPUTY_HEAD"])',
					'type' => 'object',
					'additionalProperties' => true,
					'default' => [],
				],
			],
			'additionalProperties' => false,
			'required' => ['structureId', 'name', 'parentId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$parentItem = NodeModel::createFromId((int)$args['parentId']);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_DEPARTMENT_CREATE
			: StructureActionDictionary::ACTION_TEAM_CREATE
		;
		if (!$this->checkAccess($userId, $actionId, $parentItem))
		{
			return 'Access denied';
		}

		$structureId = (int)$args['structureId'];
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
