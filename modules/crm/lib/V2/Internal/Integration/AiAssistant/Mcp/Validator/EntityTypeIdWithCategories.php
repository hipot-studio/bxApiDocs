<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

final class EntityTypeIdWithCategories extends Validator
{
	public function __construct(Dto $dto, private readonly string $entityTypeField)
	{
		parent::__construct($dto);
	}

	public function validate(array $fields): Result
	{
		if (!array_key_exists($this->entityTypeField, $fields))
		{
			return Result::success();
		}

		$entityTypeId = $fields[$this->entityTypeField] ?? null;
		if (!is_int($entityTypeId))
		{
			return Result::success();
		}

		$isSmartProcessOrDeal = $entityTypeId === CCrmOwnerType::Deal || CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId);

		if ($isSmartProcessOrDeal)
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if ($factory === null)
			{
				return Result::failEntityTypeNotSupported($entityTypeId);
			}

			if (!$factory->isCategoriesEnabled())
			{
				return Result::fail(
					"Categories are not enabled for entityTypeId {$entityTypeId}. Use an entity type with pipelines enabled."
				);
			}

			return Result::success();
		}

		return Result::fail(
			"Categories are not supported for entityTypeId {$entityTypeId}. Use this tool only for deals or smart processes with pipelines."
		);
	}
}
