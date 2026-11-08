<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;
use CCrmOwnerType;

final class CategoryService
{
	public function listCategories(int $userId, int $entityTypeId): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$validationResult = $this->validateEntityTypeWithCategories($entityTypeId, $factory);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$categories = [];
		foreach ($factory->getCategories() as $category)
		{
			if (!$this->getUserPermissions($userId)->canReadItems($category))
			{
				continue;
			}

			$categories[] = Response\Category::fromObject($category);
		}

		return Result::success(categories: $categories);
	}

	public function createCategory(int $userId, int $entityTypeId, string $title): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$validationResult = $this->validateEntityTypeWithCategories($entityTypeId, $factory);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$category = $factory->createCategory();
		if (!$this->getUserPermissions($userId)->canAdd($category))
		{
			return Result::failAccessDenied();
		}

		$result = $category
			->setName($title)
			->setSortAfterMaxCategory()
			->save()
		;
		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		$factory->clearCategoriesCache();

		return Result::success(category: Response\Category::fromObject($category));
	}

	public function renameCategory(
		int $userId,
		int $entityTypeId,
		int $categoryId,
		string $title,
	): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$validationResult = $this->validateEntityTypeWithCategories($entityTypeId, $factory);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$category = $factory->getCategory($categoryId);
		if ($category === null)
		{
			return Result::fail(
				"Category with ID {$categoryId} was not found for entityTypeId {$entityTypeId}. Use list_categories to get available category IDs."
			);
		}

		if (!$this->getUserPermissions($userId)->canUpdate($category))
		{
			return Result::failAccessDenied();
		}

		$result = $category->setName($title)->save();
		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		$factory->clearCategoriesCache();

		return Result::success(category: Response\Category::fromObject($category));
	}

	public function deleteCategory(int $userId, int $entityTypeId, int $categoryId): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$validationResult = $this->validateEntityTypeWithCategories($entityTypeId, $factory);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$category = $factory->getCategory($categoryId);
		if ($category === null)
		{
			return Result::fail(
				"Category with ID {$categoryId} was not found for entityTypeId {$entityTypeId}. Use list_categories to get available category IDs."
			);
		}

		if (!$this->getUserPermissions($userId)->canDelete($category))
		{
			return Result::failAccessDenied();
		}

		if ($category->getIsDefault())
		{
			return Result::fail("Category with ID {$categoryId} is the default category and cannot be deleted.");
		}

		$result = $category->delete();
		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		$factory->clearCategoriesCache();

		return Result::success(categoryId: $categoryId);
	}

	public function validateEntityTypeWithCategories(int $entityTypeId, ?Factory $factory = null): Result
	{
		$factory ??= $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		if ($entityTypeId !== CCrmOwnerType::Deal && !CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return Result::fail(
				"Categories are not supported for entityTypeId {$entityTypeId}. Use categoryId only for deals or smart processes with pipelines."
			);
		}

		if (!$factory->isCategoriesEnabled())
		{
			return Result::fail(
				"Categories are not enabled for entityTypeId {$entityTypeId}. Pass categoryId as null or use an entity type with pipelines enabled."
			);
		}

		return Result::success();
	}

	public function normalizeCategoryId(int $entityTypeId, ?int $categoryId): Result
	{
		$factory = $this->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		if ($entityTypeId === CCrmOwnerType::Deal || CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			if (!$factory->isCategoriesEnabled())
			{
				$categoryId = $factory->getDefaultCategory()?->getId();
			}

			if ($categoryId === null || !$factory->isCategoryExists($categoryId))
			{
				$categoryValue = $categoryId === null ? 'null' : (string)$categoryId;

				return Result::fail(
					"Category with ID {$categoryValue} was not found for entityTypeId {$entityTypeId}. Use list_categories to get available category IDs."
				);
			}
		}
		else
		{
			$categoryId = null;
		}

		return Result::success(categoryId: $categoryId);
	}

	private function getFactory(int $entityTypeId): ?Factory
	{
		return Container::getInstance()->getFactory($entityTypeId);
	}

	private function getUserPermissions(int $userId): \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Category
	{
		return Container::getInstance()->getUserPermissions($userId)->category();
	}
}
