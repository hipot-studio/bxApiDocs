<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Web\Json;

final class SearchDynamicTypeTool extends BaseCrmTool
{
	public function getName(): string
	{
		return 'search_dynamic_type';
	}

	public function getDescription(): string
	{
		return 'Searches for a dynamic type by name substring within a specific automated solution. '
			. 'Returns the dynamic type with the highest ID if multiple matches are found. '
			. 'Only works in automated solutions where the current user is an admin.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'automatedSolutionId' => [
					'description' => 'ID of the automated solution to search in. Must be a positive integer.',
					'type' => 'integer',
					'minimum' => 1,
				],
				'title' => [
					'description' => 'Name or beginning of the name of the dynamic type to search for',
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				],
			],
			'additionalProperties' => false,
			'required' => ['automatedSolutionId', 'title'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$automatedSolutionId = (int)($args['automatedSolutionId'] ?? 0);
		$title = trim((string)($args['title'] ?? ''));

		if ($automatedSolutionId <= 0)
		{
			return Json::encode(['error' => 'automatedSolutionId must be a positive integer']);
		}

		if ($title === '')
		{
			return Json::encode(['error' => 'Title is required and cannot be empty']);
		}

		$container = Container::getInstance();
		$userPermissions = $container->getUserPermissions($userId);

		if (!$userPermissions->automatedSolution()->isAutomatedSolutionAdmin($automatedSolutionId))
		{
			return Json::encode([
				'error' => 'AccessDenied',
				'message' => 'You do not have admin permissions for this automated solution',
			]);
		}

		$manager = $container->getAutomatedSolutionManager();
		$typeIds = $manager->getBoundTypeIds($automatedSolutionId);

		if (empty($typeIds))
		{
			return Json::encode([
				'error' => 'NotFound',
				'message' => "No dynamic types found in automated solution #{$automatedSolutionId}",
			]);
		}

		$escapedTitle = str_replace(['%', '_'], ['\\%', '\\_'], $title);

		$row = TypeTable::getList([
			'filter' => [
				'ID' => $typeIds,
				'%TITLE' => $escapedTitle,
			],
			'order' => ['ID' => 'DESC'],
			'select' => ['ID', 'TITLE', 'ENTITY_TYPE_ID'],
			'limit' => 1,
		])->fetch();

		if ($row === false)
		{
			return Json::encode([
				'error' => 'NotFound',
				'message' => "No dynamic type found matching '{$title}' in automated solution #{$automatedSolutionId}",
			]);
		}

		return Json::encode([
			'id' => (int)$row['ENTITY_TYPE_ID'],
			'title' => $row['TITLE'],
		]);
	}
}
