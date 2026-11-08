<?php

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Util\Type;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\Util\UserField;
use Bitrix\Tasks\V2\Internal\Entity\UserFieldSchemeCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\UserFieldSchemeMapper;
use CUserTypeEntity;

class UserFieldSchemeRepository implements UserFieldSchemeRepositoryInterface
{
	public function __construct(
		private readonly UserFieldSchemeMapper $userFieldSchemeMapper,
	)
	{

	}

	public function getCollection(int $userId, string $entityCode): UserFieldSchemeCollection
	{
		$scheme = $this->getControllerClass($entityCode)::getScheme($entityCode, $userId);
		$scheme = $this->sortByUserOption($scheme, $userId, $entityCode);

		return $this->userFieldSchemeMapper->mapToCollection($scheme);
	}

	public function hasMandatory(string $entityCode): bool
	{
		$fields = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityCode, 'MANDATORY' => 'Y']);

		return (bool)$fields->Fetch();
	}

	private function getControllerClass(string $entityCode): UserField
	{
		$className = UserField::getControllerClassByEntityCode($entityCode);

		return new $className();
	}

	private function sortByUserOption(array $scheme, int $userId, string $entityCode): array
	{
		$optionName = $entityCode === 'TASKS_TASK'
			? 'tasks_component_ufp_state'
			: 'tc_' . mb_strtolower($entityCode) . '_ufp_st';
		$state = Type::unSerializeArray(User::getOption($optionName, $userId));

		if ($state === [])
		{
			$state = Type::unSerializeArray(User::getOption($optionName, User::getAdminId()));
		}

		if ($state === [])
		{
			return $scheme;
		}

		uasort($scheme, static function (array $left, array $right) use ($state): int {
			$leftSort = $state[$left['ID']]['S'] ?? $left['SORT'];
			$rightSort = $state[$right['ID']]['S'] ?? $right['SORT'];

			return (int)$leftSort <=> (int)$rightSort;
		});

		return $scheme;
	}
}
