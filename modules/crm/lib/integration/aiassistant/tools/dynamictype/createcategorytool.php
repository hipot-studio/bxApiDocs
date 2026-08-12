<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Web\Json;

final class CreateCategoryTool extends BaseCrmTool
{
	public function getName(): string
	{
		return 'create_category';
	}

	public function getDescription(): string
	{
		return 'Creates a new funnel (category) with default stages for a dynamic type. '
			. 'Requires the entityTypeId of the dynamic type. ';
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
				'name' => [
					'description' => 'Name of the funnel to be created',
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				],
			],
			'additionalProperties' => false,
			'required' => ['entityTypeId', 'name'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$entityTypeId = (int)($args['entityTypeId'] ?? 0);
		$name = trim((string)($args['name'] ?? ''));

		if ($entityTypeId <= 0)
		{
			return Json::encode(['error' => 'entityTypeId must be a positive integer']);
		}

		if ($name === '')
		{
			return Json::encode(['error' => 'Name is required and cannot be empty']);
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

		if (!$factory->isCategoriesEnabled())
		{
			return Json::encode([
				'error' => 'CategoriesDisabled',
				'message' => "Funnels are disabled for the dynamic type with entityTypeId {$entityTypeId}",
			]);
		}

		$categoryPermissions = $container->getUserPermissions($userId)->category();
		$category = $factory->createCategory();

		if (!$categoryPermissions->canAdd($category))
		{
			return Json::encode([
				'error' => 'AccessDenied',
				'message' => 'You do not have permission to create categories for this dynamic type',
			]);
		}

		$result = $category
			->setName($name)
			->setSortAfterMaxCategory()
			->save()
		;

		if (!$result->isSuccess())
		{
			return Json::encode([
				'error' => 'Failed to create category',
				'details' => implode(', ', $result->getErrorMessages()),
			]);
		}

		$factory->clearCategoriesCache();

		return Json::encode([
			'categoryId' => $category->getId(),
			'name' => $name,
		]);
	}
}
