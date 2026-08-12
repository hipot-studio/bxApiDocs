<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Search\Content;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\AllowGuestsInvitationField;
use Bitrix\Socialnetwork\Collab\Provider\CollabProvider;
use Bitrix\Socialnetwork\Collab\Provider\CollabQuery;
use Bitrix\Socialnetwork\V2\Internal\Repository\CollabOptionRepository;
use Bitrix\Socialnetwork\UserToGroupTable;
use CSocNetUser;

class CollabRepository
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('socialnetwork');
	}

	public function getCollabsByName(
		int $userId,
		string $name,
		int $limit = 30,
		int $offset = 0,
	): array
	{
		if ($userId <= 0 || !$this->isAvailable)
		{
			return [];
		}

		$filter = Query::filter()->where('ACTIVE', 'Y');
		$name = trim($name);

		if (
			$name !== ''
			&& !$this->applyNameFilter($filter, $name)
		)
		{
			return [];
		}

		$query = (new CollabQuery($userId))
			->setSelect(['ID', 'NAME'])
			->setOrder(['NAME' => 'ASC', 'ID' => 'ASC'])
			->setWhere($filter)
			->setLimit($limit)
		;

		if ($offset > 0)
		{
			$query->setOffset($offset);
		}

		if (!CSocNetUser::isUserModuleAdmin($userId))
		{
			$query->setAccessCheck();
		}

		$result = [];
		$collabIds = [];
		$collabs = CollabProvider::getInstance()->getList($query);
		foreach ($collabs as $collab)
		{
			$id = $collab->getId();
			if ($id <= 0)
			{
				continue;
			}

			$collabIds[] = $id;
			$result[] = [
				'id' => $id,
				'name' => $collab->getName(),
			];
		}

		$allowedGuestsInvitationById = $this->getAllowedGuestsInvitationById($collabIds);
		foreach ($result as $index => $collab)
		{
			$result[$index]['isAllowedGuestsInvitation'] = $allowedGuestsInvitationById[(int)$collab['id']] ?? false;
		}

		return $result;
	}

	private function getAllowedGuestsInvitationById(array $collabIds): array
	{
		if ($collabIds === [])
		{
			return [];
		}

		if (!class_exists(AllowGuestsInvitationField::class))
		{
			return array_fill_keys($collabIds, true);
		}

		$optionValues = (new CollabOptionRepository())->getOptionValueBatch(
			$collabIds,
			AllowGuestsInvitationField::DB_NAME,
		);

		$result = [];
		foreach ($collabIds as $collabId)
		{
			$value = $optionValues[$collabId] ?? AllowGuestsInvitationField::DEFAULT_VALUE;
			$result[$collabId] = $value === 'Y';
		}

		return $result;
	}

	private function applyNameFilter(ConditionTree $filter, string $name): bool
	{
		$searchToken = (
			Content::isIntegerToken($name)
			? Content::prepareIntegerToken($name)
			: Content::prepareStringToken($name)
		);

		if (!Content::canUseFulltextSearch($searchToken, Content::TYPE_MIXED))
		{
			return false;
		}

		$filter->whereMatch('SEARCH_INDEX', Helper::matchAgainstWildcard($searchToken));

		return true;
	}

	public function getInvitations(
		int $projectId,
		?array $invitationIds = null,
		?array $inviteeIds = null,
		int $limit = 100,
		int $offset = 0,
		?int $afterId = null,
		?array $statuses = null,
	): array
	{
		if (
			!$this->isAvailable
			|| ($invitationIds !== null && empty($invitationIds))
			|| ($inviteeIds !== null && empty($inviteeIds))
		)
		{
			return [];
		}

		$roles = $this->resolveInvitationRolesByStatuses($statuses);
		if ($roles === [])
		{
			return [];
		}

		$query = UserToGroupTable::query()
			->where('GROUP_ID', $projectId)
			->where('INITIATED_BY_TYPE', UserToGroupTable::INITIATED_BY_GROUP)
			->whereIn('ROLE', $roles)
			->setOrder(['ID' => 'ASC'])
			->setSelect([
				'ID',
				'USER_ID',
				'GROUP_ID',
				'ROLE',
				'INITIATED_BY_TYPE',
				'DATE_CREATE',
				'DATE_UPDATE',
				'INITIATED_BY_USER_ID',
			])
			->setLimit($limit)
		;

		if ($afterId !== null && $afterId > 0)
		{
			$query->where('ID', '>', $afterId);
		}
		elseif ($offset > 0)
		{
			$query->setOffset($offset);
		}

		if ($invitationIds !== null)
		{
			$query->whereIn('ID', $invitationIds);
		}

		if ($inviteeIds !== null)
		{
			$query->whereIn('USER_ID', $inviteeIds);
		}

		return $query->fetchAll();
	}

	private function resolveInvitationRolesByStatuses(?array $statuses): array
	{
		$roles = [];
		$statuses = $statuses ?? [];

		if ($statuses === [])
		{
			$roles[] = UserToGroupTable::ROLE_REQUEST;

			return array_merge($roles, UserToGroupTable::getRolesMember());
		}

		$statusValues = array_map(
			static fn($status): string => $status instanceof InvitationStatus ? $status->value : (string)$status,
			$statuses,
		);

		if (in_array(InvitationStatus::INVITED->value, $statusValues, true))
		{
			$roles[] = UserToGroupTable::ROLE_REQUEST;
		}

		if (in_array(InvitationStatus::ACTIVE->value, $statusValues, true))
		{
			$roles = array_merge($roles, UserToGroupTable::getRolesMember());
		}

		return array_values(array_unique($roles));
	}
}
