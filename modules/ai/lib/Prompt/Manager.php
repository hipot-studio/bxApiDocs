<?php

namespace Bitrix\AI\Prompt;

use Bitrix\AI\Cache;
use Bitrix\AI\Entity\Prompt;
use Bitrix\AI\Facade\User;
use Bitrix\AI\Model\PromptTable;
use Bitrix\AI\Model\RoleTable;
use Bitrix\AI\Role\Industry;
use Bitrix\AI\Role\RoleManager;
use Bitrix\AI\Updater;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Type\DateTime;

class Manager
{
	private const CACHE_DIR = 'ai.prompt';

	/**
	 * Returns Prompt Item by code.
	 *
	 * @param string $code Prompt's code.
	 * @return Item|null
	 */
	public static function getByCode(string $code): ?Item
	{
		static $prompts = [];

		if (array_key_exists($code, $prompts))
		{
			return $prompts[$code];
		}

		$prompt = PromptTable::query()
			->setSelect(['*'])
			->where('CODE', $code)
			->setLimit(1)
			->fetchObject()
		;

		$prompts[$code] = $prompt ? self::getItemFromRow($prompt) : null;
		return $prompts[$code];
	}

	/**
	 * Returns Prompts by category in Tree mode from cache.
	 *
	 * @param string $category Prompt's category.
	 * @param string|null $roleCode Role code.
	 * @return array|null
	 */
	public static function getCachedTree(string $category, ?string $roleCode = null): ?array
	{
		$cacheId = $category . $roleCode . User::getUserLanguage();
		return Cache::getDynamic(self::CACHE_DIR, $cacheId, fn() => self::getTree($category, $roleCode));
	}

	/**
	 * Returns Prompts by category in Tree mode.
	 *
	 * @param string $category Prompt's category.
	 * @param string|null $roleCode Role code.
	 * @return array|null
	 */
	public static function getTree(string $category, ?string $roleCode): ?array
	{
		$prompts = self::getByCategory($category, $roleCode);
		if (!$prompts->isEmpty())
		{
			$result = [];
			$prevPromptSection = null;

			foreach ($prompts as $prompt)
			{
				$children = [];
				foreach ($prompt->getChildren() as $child)
				{
					$children[] = [
						'code' => $child->getCode(),
						'type' => $prompt->getType(),
						'icon' => $child->getIcon(),
						'title' => $child->getTitle(),
						'text' => $prompt->getText(),
						'required' => [
							'user_message' => $child->isRequiredUserMessage(),
							'context_message' => $child->isRequiredOriginalMessage(),
						],
					];
				}

				if ($prompt->getSectionCode() && $prompt->getSectionCode() !== $prevPromptSection)
				{
					$result[] = [
						'separator' => true,
						'title' => $prompt->getSectionTitle(),
						'section' => $prompt->getSectionCode(),
					];
				}

				$result[] = [
					'section' => $prompt->getSectionCode(),
					'code' => $prompt->getCode(),
					'type' => $prompt->getType(),
					'icon' => $prompt->getIcon(),
					'title' => $prompt->getTitle(),
					'text' => $prompt->getText(),
					'workWithResult' => $prompt->isWorkWithResult(),
					'children' => $children,
					'required' => [
						'user_message' => $prompt->isRequiredUserMessage(),
						'context_message' => $prompt->isRequiredOriginalMessage(),
					],
				];

				$prevPromptSection = $prompt->getSectionCode();
			}

			// sort by section
			usort($result, function($a, $b)
			{
				if ($a['section'] === $b['section'])
				{
					return 0;
				}
				return $a['section'] > $b['section'];
			});

			return $result;
		}

		return null;
	}

	/**
	 * Deletes all Prompts by filter.
	 *
	 * @param array $filterToDelete Query's filter to delete.
	 * @return bool
	 */
	public static function deleteByFilter(array $filterToDelete): bool
	{
		$result = true;
		$dataExists = false;

		$prompts = PromptTable::query()
			->setSelect(['ID'])
			->setFilter($filterToDelete)
		;
		foreach ($prompts->fetchAll() as $prompt)
		{
			$dataExists = true;
			$result = self::deleteByFilter(['PARENT_ID' => $prompt['ID']])
						&& PromptTable::delete($prompt['ID'])->isSuccess()
						&& $result;
		}

		if ($dataExists && $result)
		{
			self::clearCache();
		}

		return $result;
	}

	/**
	 * Removes cached data.
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		Cache::remove(self::CACHE_DIR);
	}

	/**
	 * Create Prompt's Item from table object.
	 *
	 * @param Prompt $data Raw data.
	 * @return Item
	 */
	private static function getItemFromRow(Prompt $data): Item
	{
		return new Item(
			$data->getId(),
			$data->getSection(),
			$data->getCode(),
			$data->getType(),
			$data->getAppCode(),
			$data->getIcon(),
			$data->getPrompt(),
			$data->getTranslate(),
			$data->getTextTranslates(),
			$data->getSettings(),
			$data->getCacheCategory(),
			$data->getCategory(),
			$data->getWorkWithResult() === 'Y',
		);
	}

	/**
	 * Returns Prompt's raw tree by category code.
	 *
	 * @param string $code Category code.
	 * @param string|null $roleCode Role code.
	 * @return Collection
	 */
	private static function getByCategory(string $code, ?string $roleCode): Collection
	{
		$role = RoleTable::query()->setFilter(['CODE' => ($roleCode ?? RoleManager::getUniversalRoleCode())])->fetchObject();

		$collection = [];
		$select = ['*', 'ROLES.ID', 'ROLES.CODE'];
		$order = ['SORT' => 'asc'];

		// first retrieve root categories
		$rootPrompts = PromptTable::query()
			->setSelect($select)
			->where('PARENT_ID', null)
			->setOrder($order)
			->fetchCollection()
		;
		foreach ($rootPrompts as $rootPrompt)
		{
			if (
				in_array($code, (array)$rootPrompt->getCategory(), true)
				&& ($rootPrompt->getType() === null || ($role && $rootPrompt->getRoles()->has($role)))
			)
			{
				$collection[$rootPrompt->getId()] = self::getItemFromRow($rootPrompt);
			}
		}

		// then retrieve all child prompts
		$childPrompts = PromptTable::query()
			->setSelect($select)
			->setFilter(['PARENT_ID' => array_keys($collection)])
			->setOrder($order)
			->fetchCollection()
		;

		foreach ($childPrompts as $childPrompt)
		{
			$collection[$childPrompt->getParentId()]->addChild(
				self::getItemFromRow($childPrompt)
			);
		}

		return new Collection(array_values($collection));
	}

	/**
	 * Deletes all system prompts from local DB and loads new.
	 *
	 * @return void
	 */
	public static function clearAndRefresh(): void
	{
		Updater::refreshFromRemote();
	}
}
