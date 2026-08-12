<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Web\Json;

final class ListFieldsTool extends BaseCrmTool
{
	public function getName(): string
	{
		return 'list_dynamic_type_fields';
	}

	public function getDescription(): string
	{
		return 'Lists all fields (system and custom) available for a dynamic type. '
			. 'Use this tool before create_card_view to discover valid field names '
			. '(e.g., TITLE, ASSIGNED_BY_ID, UF_CRM_123).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'entityTypeId' => [
					'description' => 'Entity type identifier of the dynamic type. Use search_dynamic_type to find it.',
					'type' => 'integer',
					'minimum' => 1,
				],
			],
			'additionalProperties' => false,
			'required' => ['entityTypeId'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$entityTypeId = (int)($args['entityTypeId'] ?? 0);

		if ($entityTypeId <= 0)
		{
			return Json::encode(['error' => 'entityTypeId must be a positive integer']);
		}

		$container = Container::getInstance();
		$factory = $container->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Json::encode([
				'error' => 'EntityTypeNotFound',
				'message' => "Dynamic type with entityTypeId {$entityTypeId} not found",
			]);
		}

		if (!$container->getUserPermissions($userId)->isAdminForEntity($entityTypeId))
		{
			return Json::encode([
				'error' => 'AccessDenied',
				'message' => 'You do not have admin permissions for this dynamic type',
			]);
		}

		$fields = [];
		foreach ($factory->getFieldsCollection() as $field)
		{
			if ($field->isHidden() || !$field->isDisplayed())
			{
				continue;
			}

			$fields[] = [
				'name' => $field->getName(),
				'title' => $field->getTitle(),
				'type' => $field->getType(),
				'isMultiple' => $field->isMultiple(),
				'isRequired' => $field->isRequired(),
				'isUserField' => $field->isUserField(),
			];
		}

		return Json::encode(['fields' => $fields]);
	}
}
