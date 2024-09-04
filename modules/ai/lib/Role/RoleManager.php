<?php

declare(strict_types = 1);

namespace Bitrix\AI\Role;

use Bitrix\AI\Prompt;
use Bitrix\AI\Dto\PromptDto;
use Bitrix\AI\Dto\PromptType;
use Bitrix\AI\Entity\Role;
use Bitrix\AI\Model\EO_Role_Collection;
use Bitrix\AI\Model\RoleFavoriteTable;
use Bitrix\AI\Model\RoleIndustryTable;
use Bitrix\AI\Model\RecentRoleTable;
use Bitrix\AI\Model\RoleTable;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

class RoleManager
{
	private const UNIVERSAL_ROLE_CODE = 'copilot_assistant';
	private const RECENT_ROLE_LIMIT = 10;

	private int $userId;
	private string $languageCode;

	/**
	 * @param int $userId
	 * @param string $language
	 */
	public function __construct(int $userId, string $language)
	{
		$this->userId = $userId;
		$this->languageCode = $language;
	}

	/**
	 * Get exists roles list by code
	 *
	 * @param string[] $roleCodes
	 *
	 * @return array
	 */
	public function getRolesByCode(array $roleCodes): array
	{
		$roles = RoleTable::query()->setSelect(['*'])->setFilter(['=CODE' => $roleCodes])->fetchCollection();

		return $this->convertRolesCollectionToArray($roles);
	}

	/**
	 * Get exists role by code
	 *
	 * @param string $roleCode
	 *
	 * @return array|null
	 */
	public function getRoleByCode(string $roleCode): array|null
	{
		$role = RoleTable::query()->setSelect(['*'])->setFilter(['=CODE' => $roleCode])->fetchObject();
		if (!$role)
		{
			return null;
		}

		return $this->convertRoleToArray($role);
	}

	/**
	 * Returns roles list by industry.
	 *
	 * @return array
	 */
	public function getIndustriesWithRoles(): array
	{
		$result = [];
		$industries = RoleIndustryTable::query()
			->setSelect(['CODE', 'NAME_TRANSLATES', 'ROLES', 'IS_NEW'])
			->setOrder(['SORT' => 'ASC', 'ROLES.IS_NEW' => 'DESC', 'ROLES.SORT' => 'ASC'])
			->fetchCollection()
		;

		foreach ($industries as $industry)
		{
			$result[] = [
				'code' => $industry->getCode(),
				'name' => $industry->getName($this->languageCode),
				'roles' => $this->convertRolesCollectionToArray($industry->getRoles()),
				'isNew' => $industry->getIsNew(),
			];
		}

		return $result;
	}

	/**
	 * Get list of recommended roles
	 *
	 * @param int $limit
	 *
	 * @return array
	 */
	public function getRecommendedRoles(int $limit = 10): array
	{
		if ($limit < 0)
		{
			$limit = 10;
		}

		$roles = RoleTable::query()
	  		->setSelect(['*'])
			->setFilter(['IS_RECOMMENDED' => true])
			->setOrder(['IS_NEW' => 'DESC', 'SORT' => 'ASC'])
			->setLimit($limit)
			->fetchCollection()
		;

		return $this->convertRolesCollectionToArray($roles);
	}

	/**
	 * Return universal role code for default
	 *
	 * @return string
	 */
	public static function getUniversalRoleCode(): string
	{
		return self::UNIVERSAL_ROLE_CODE;
	}

	/**
	 * Return universal role
	 *
	 * @return array|null
	 */
	public function getUniversalRole(): array|null
	{
		return $this->getRoleByCode(self::UNIVERSAL_ROLE_CODE);
	}

	/**
	 * Convert roles collection to array.
	 *
	 * @param EO_Role_Collection $roles
	 * @return array
	 */
	private function convertRolesCollectionToArray(EO_Role_Collection $roles): array
	{
		$items = [];
		foreach ($roles as $role)
		{
			$items[] = $this->convertRoleToArray($role);
		}

		return $items;
	}

	/**
	 * Convert role to array.
	 *
	 * @param Role $role
	 *
	 * @return array
	 */
	private function convertRoleToArray(Role $role): array
	{
		return [
			'code' => $role->getCode(),
			'name' => $role->getName($this->languageCode),
			'description' => $role->getDescription($this->languageCode),
			'avatar' => $role->getAvatar(),
			'industryCode' => $role->getIndustryCode(),
			'isNew' => $role->getIsNew(),
			'isRecommended' => $role->getIsRecommended(),
		];
	}

	/**
	 * Save role code to recent role table.
	 *
	 * @param Prompt\Role $role role code.
	 * @return void
	 */
	public function addRecentRole(Prompt\Role $role): void
	{
		$helper = Application::getConnection()->getSqlHelper();

		$merge = $helper->prepareMerge(
			RecentRoleTable::getTableName(),
			['ROLE_CODE', 'USER_ID'],
			[
				'ROLE_CODE' => $role->getCode(),
				'USER_ID' => $this->userId,
			],
			[
				'ROLE_CODE' => $role->getCode(),
				'USER_ID' => $this->userId,
				'DATE_TOUCH' => new DateTime(),
			]
		);

		if ($merge[0] != '')
		{
			Application::getConnection()->query($merge[0]);
		}

		$this->deleteRecentWithProbability();
	}

	/**
	 * Get list of recent used roles
	 *
	 * @return array
	 */
	public function getRecentRoles(): array
	{
		$recentsRoles = RecentRoleTable::query()
			->setSelect([
				'ROLE',
			])
			->setFilter(['USER_ID' => $this->userId, '!=ROLE.CODE' => [self::UNIVERSAL_ROLE_CODE, 'copilot_assistant_chat']])
			->setOrder(['DATE_TOUCH' => 'DESC'])
			->setLimit(self::RECENT_ROLE_LIMIT)
			->fetchCollection()
		;

		$roles = [];
		foreach ($recentsRoles as $role)
		{
			$roles[] = $this->convertRoleToArray($role->getRole());
		}

		return $roles;
	}

	/**
	 * Add role to favorite role table.
	 *
	 * @param Prompt\Role $role role code.
	 *
	 * @return void
	 */
	public function addFavoriteRole(Prompt\Role $role): void
	{
		$exists = RoleFavoriteTable::query()
			->setSelect(['ID'])
			->setFilter([
				'=ROLE_CODE' => $role->getCode(),
				'USER_ID' => $this->userId,
			])
			->fetchObject()
		;

		if ($exists !== null)
		{
			return;
		}

		RoleFavoriteTable::add([
			'ROLE_CODE' => $role->getCode(),
			'USER_ID' => $this->userId,
		]);
	}

	/**
	 * Remove role code from favorite role table.
	 *
	 * @param Prompt\Role $role role code.
	 *
	 * @return void
	 */
	public function removeFavoriteRole(Prompt\Role $role): void
	{
		RoleFavoriteTable::deleteByFilter([
			'ROLE_CODE' => $role->getCode(),
			'USER_ID' => $this->userId,
		]);
	}

	/**
	 * Return list of favorite roles.
	 *
	 * @return array
	 */
	public function getFavoriteRoles(): array
	{
		$favoriteRoles = RoleFavoriteTable::query()
			->setSelect(['ROLE'])
			->setFilter(['USER_ID' => $this->userId])
			->setOrder(['DATE_CREATE' => 'DESC'])
			->setLimit(self::RECENT_ROLE_LIMIT)
			->fetchCollection()
		;

		$roles = [];
		foreach ($favoriteRoles as $role)
		{
			$roles[] = $this->convertRoleToArray($role->getRole());
		}

		return $roles;
	}

	/**
	 * Get list prompts by category and roleCode
	 *
	 * @param string $category
	 * @param string $roleCode
	 *
	 * @return PromptDto[]
	 */
	public function getPromptsBy(string $category, string $roleCode): array
	{
		$prompts = [];
		$role = RoleTable::query()
			->setSelect(['PROMPTS'])
			->setFilter(['=CODE' => $roleCode])
			->setOrder(['PROMPTS.IS_NEW' => 'DESC', 'SORT' => 'ASC'])
			->fetchObject()
		;

		if($role === null)
		{
			return $prompts;
		}

		$result = $role->getPrompts();

		foreach ($result as $prompt)
		{
			if (!in_array($category, $prompt->getCategory(), true))
			{
				continue;
			}

			$prompts[] = new PromptDto(
				$prompt->getCode(),
				(new \ReflectionEnum(PromptType::class))->getCase($prompt->getType())->getValue(),
				$prompt->getName($this->languageCode),
				$prompt->getText($this->languageCode),
				$prompt->getIsNew(),
			);
		}

		return $prompts;
	}

	/**
	 * Run delete with probability
	 *
	 * @return void
	 */
	public function deleteRecentWithProbability(): void
	{
		if (mt_rand(0,100) < 10)
		{
			$this->deleteRecentOverLimit();
		}
	}

	/**
	 * delete recent items over limit.
	 *
	 * @return void
	 */
	private function deleteRecentOverLimit(): void
	{
		$items = RecentRoleTable::query()
			->setSelect(['ID'])
			->setFilter(['USER_ID' => $this->userId])
			->setOrder(['DATE_TOUCH' => 'DESC'])
			->fetchAll()
		;

		if(count($items) > self::RECENT_ROLE_LIMIT)
		{
			$itemsForDelete = array_slice($items, self::RECENT_ROLE_LIMIT);
			RecentRoleTable::deleteByFilter(['ID' => $itemsForDelete]);
		}
	}
}
