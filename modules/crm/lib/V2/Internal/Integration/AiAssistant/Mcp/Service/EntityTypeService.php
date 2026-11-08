<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;
use CCrmOwnerType;

final readonly class EntityTypeService
{
	private const BUILTIN_TYPE_IDS = [
		CCrmOwnerType::Lead,
		CCrmOwnerType::Deal,
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
		CCrmOwnerType::Quote,
		CCrmOwnerType::SmartInvoice,
	];

	public function listEntityTypes(int $userId, ?string $query = null): Result
	{
		$entityTypes = [];
		foreach (self::BUILTIN_TYPE_IDS as $entityTypeId)
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if ($factory === null)
			{
				continue;
			}

			$entityTypes[] = Response\EntityType::fromFactory($factory);
		}

		foreach ($this->loadDynamicTypes() as $type)
		{
			$entityTypes[] = Response\EntityType::fromDynamicType($type);
		}

		$permissions = Container::getInstance()->getUserPermissions($userId)->entityType();
		$entityTypes = array_filter(
			$entityTypes,
			static fn (Response\EntityType $entityType) => $permissions->canReadItems($entityType->entityTypeId),
		);

		if (!empty($query))
		{
			$entityTypes = array_filter(
				$entityTypes,
				static fn (Response\EntityType $entityType) => str_contains(mb_strtolower($entityType->title), mb_strtolower($query)),
			);
		}

		return Result::success(entityTypes: array_values($entityTypes));
	}

	/**
	 * @return Type[]
	 */
	public function loadDynamicTypes(): array
	{
		return Container::getInstance()
			->getDynamicTypesMap()
			->load([
				'isLoadStages' => false,
				'isLoadCategories' => false,
			])
			->getTypes()
		;
	}
}
