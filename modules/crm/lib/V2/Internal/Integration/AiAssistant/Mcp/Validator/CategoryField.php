<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

final class CategoryField extends Validator
{
	public function __construct(
		Dto $dto,
		private readonly string $entityTypeIdField,
		private readonly string $categoryField,
	)
	{
		parent::__construct($dto);
	}

	public function validate(array $fields): Result
	{
		$entityTypeId = $fields[$this->entityTypeIdField] ?? null;
		if (!is_numeric($entityTypeId))
		{
			return new Result();
		}

		$factory = Container::getInstance()->getFactory((int)$entityTypeId);
		if ($factory === null)
		{
			return new Result();
		}

		$categoryId = $fields[$this->categoryField] ?? null;
		if ($categoryId !== null && !is_int($categoryId))
		{
			return new Result();
		}

		$isSmartProcessOrDeal = $factory->getEntityTypeId() === CCrmOwnerType::Deal
			|| CCrmOwnerType::isPossibleDynamicTypeId($factory->getEntityTypeId())
		;

		if ($isSmartProcessOrDeal)
		{
			if (!$factory->isCategoriesEnabled())
			{
				if ($categoryId === null)
				{
					return Result::success();
				}

				return Result::fail(
					"Field '{$this->categoryField}' must be null because categories are not enabled for entityTypeId {$entityTypeId}."
				);
			}

			if (!is_numeric($categoryId) || !$factory->isCategoryAvailable((int)$categoryId))
			{
				$categoryValue = $categoryId === null ? 'null' : (string)$categoryId;

				return Result::fail(
					"Category with ID {$categoryValue} is not available for entityTypeId {$entityTypeId}. Use list_categories to get available category IDs."
				);
			}

			return Result::success();
		}

		if ($categoryId !== null)
		{
			return Result::fail(
				"Field '{$this->categoryField}' must be null because categories are not supported for entityTypeId {$entityTypeId}."
			);
		}

		return Result::success();
	}
}
