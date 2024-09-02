<?php

declare(strict_types = 1);

namespace Bitrix\AI\Synchronization;

use Bitrix\AI\Entity\Prompt;
use Bitrix\AI\Entity\Role;
use Bitrix\AI\Model\EO_Role_Collection;
use Bitrix\AI\Model\PromptTable;
use Bitrix\AI\Model\RoleTable;
use Bitrix\AI\Prompt\Manager;
use Bitrix\Main\Entity\AddResult;
use Bitrix\Main\Entity\UpdateResult;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class PromptSync extends BaseSync
{
	protected RoleTable $roleDataManager;

	/**
	 * @inheritDoc
	 */
	protected function getDataManager(): PromptTable
	{
		return $this->dataManager ?? ($this->dataManager = new PromptTable());
	}

	/**
	 * return role query manager
	 */
	protected function getRoleQueryBuilder(): Query
	{
		return (new RoleTable())::query();
	}

	/**
	 * @inheritDoc
	 */
	public function sync(array $items, array $filter = []): void
	{
		$oldIds = $this->getIdsByFilter($filter);
		$currentIds = [];
		$rootSort = 0;
		foreach ($items as $rootCode => $rootAbility)
		{
			$rootSort += 100;
			$rootAbility['sort'] = $rootSort;
			$rootAbility['is_system'] = 'Y';
			$result = $this->addOrUpdate($rootAbility);
			if ($result->isSuccess())
			{
				$currentIds[] = $result->getId();
			}
			else
			{
				$this->log('AI_DB_SYNC_ERROR: ' . implode('; ', $result->getErrorMessages()));
			}

			if ($result->isSuccess() && !empty($rootAbility['abilities']))
			{
				$childSort = 0;
				foreach ($rootAbility['abilities'] as $childAbility)
				{
					$childSort += 100;
					$childAbility['sort'] = $childSort;
					$childAbility['is_system'] = 'Y';
					$childAbility['parent_code'] = $rootCode;
					$childAbility['settings'] = $rootAbility['settings'] ?? [];
					$childAbility['category'] = $rootAbility['category'] ?? [];
					$result = $this->addOrUpdate($childAbility);
					if ($result->isSuccess())
					{
						$currentIds[] = $result->getId();
					}
					else
					{
						$this->log('AI_DB_SYNC_ERROR: ' . implode('; ', $result->getErrorMessages()));
					}
				}
			}

		}

		$idsForDelete = array_diff($oldIds, $currentIds);
		foreach ($idsForDelete as $id)
		{
			$this->delete($id);
		}
	}

	/**
	 * Method for compatibility with prompt rest.
	 *
	 * @param array $fields
	 *
	 * @return AddResult|UpdateResult
	 */
	public function syncPrompt(array $fields): AddResult|UpdateResult
	{
		return $this->addOrUpdate($fields);
	}

	protected function addOrUpdate(array $fields): AddResult|UpdateResult
	{
		$fields = array_change_key_case($fields, CASE_UPPER);

		$filterExists = array_key_exists('APP_CODE', $fields)
			? ['=APP_CODE' => $fields['APP_CODE']]
			: [];

		if (array_key_exists('CODE', $fields))
		{
			$filterExists['=CODE'] = $fields['CODE'];
		}

		if (array_key_exists('CATEGORY', $fields) && !is_array($fields['CATEGORY']))
		{
			$fields['CATEGORY'] = (array)$fields['CATEGORY'];
		}

		if (array_key_exists('CACHE_CATEGORY', $fields) && !is_array($fields['CACHE_CATEGORY']))
		{
			$fields['CACHE_CATEGORY'] = (array)$fields['CACHE_CATEGORY'];
		}

		if (array_key_exists('PARENT_CODE', $fields))
		{
			$parent = $this->getQueryBuilder()
				->setSelect(['ID'])
				->setFilter(['PARENT_ID' => null, '=CODE' => $fields['PARENT_CODE']])
				->setLimit(1)
				->fetch()
			;
			if (!$parent)
			{
				$result = new UpdateResult();
				$result->addError(new Error('Parent prompt not found.', 'PARENT_NOT_FOUND'));
				return $result;
			}

			$fields['PARENT_ID'] = $parent['ID'];
		}

		$exists = null;

		if (!empty($filterExists))
		{
			$exists = $this->getByFilter($filterExists);
		}

		if (array_key_exists('SETTINGS', $fields) && empty($fields['SETTINGS']))
		{
			$fields['SETTINGS'] = [];
		}

		// prepare roles
		$roles = $fields['ROLES'] ?? [];
		unset($fields['ROLES']);
		if (!is_array($roles))
		{
			$roles = [];
		}

		if (is_null($exists))
		{
			$result = $this->add($fields);
			if ($result->isSuccess())
			{
				// add link roles to prompt
				$this->updatePromptRoles($result->getId(), $roles);

				Manager::clearCache();
			}

			return $result;
		}

		if ($exists['HASH'] === ($fields['HASH'] ?? null))
		{
			// fake update
			$result = new UpdateResult();
			$result->setPrimary(['ID' => $exists['ID']]);
			return $result;
		}

		$this->updatePromptRoles($exists['ID'], $roles);

		$fields['DATE_MODIFY'] = new DateTime();

		$result = $this->update($exists['ID'], $fields);
		if ($result->isSuccess())
		{
			Manager::clearCache();
		}

		return $result;
	}

	protected function getById(int|string $promptId): Prompt
	{
		return $this->getDataManager()::getById($promptId)->fetchObject();
	}

	private function updatePromptRoles(int|string $promptId, array $roleCodes): void
	{
		$prompt = $this->getById($promptId);
		if (!$prompt)
		{
			return;
		}

		if (!$prompt->isRolesFilled())
		{
			$prompt->fillRoles();
		}

		if (!count($roleCodes))
		{
			$prompt->removeAllRoles();
			$this->savePrompt($prompt);
			return;
		}

		$roles = $this->getRolesByCodes($roleCodes);
		$promptRoles = $prompt->getRoles();
		$roleCodesExists = [];
		// add new prompt roles
		foreach ($roles as $role)
		{
			$roleCodesExists[] = $role->getCode();
			if (!$prompt->getRoles()->has($role))
			{
				$prompt->addToRoles($role);
			}
		}
		// remove old prompt roles
		foreach ($promptRoles as $role)
		{
			if (!in_array($role->getCode(), $roleCodesExists, true))
			{
				$prompt->removeFromRoles($role);
			}
		}

		$this->savePrompt($prompt);
	}

	protected function savePrompt(Prompt $prompt): Result
	{
		return $prompt->save();
	}

	/**
	 * Return role collection by role codes
	 *
	 * @param array $roleCodes
	 *
	 * @return EO_Role_Collection
	 */
	protected function getRolesByCodes(array $roleCodes): EO_Role_Collection
	{
		return $this->getRoleQueryBuilder()->setSelect(['ID', 'CODE'])->setFilter(['CODE' => $roleCodes])->fetchCollection();
	}
}
