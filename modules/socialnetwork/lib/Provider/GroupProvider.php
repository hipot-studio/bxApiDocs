<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Provider;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Socialnetwork\Internals\Registry\Event\GroupLoadedEvent;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Socialnetwork\Helper\InstanceTrait;

class GroupProvider
{
	use InstanceTrait;

	protected static array $checkedGroups = [];
	protected static array $groupTypes = [];
	protected static array $collabProjects = [];

	public function isExistingGroup(string $inputName, int $groupId = 0): bool
	{
		$name = trim($inputName);

		if (empty($name))
		{
			return false;
		}

		if (isset(static::$checkedGroups[$name]))
		{
			return static::$checkedGroups[$name];
		}

		$query = WorkgroupTable::query()
			->setSelect(['ID'])
			->where('NAME', $name);

		if ($groupId > 0)
		{
			$query->whereNot('ID', $groupId);
		}

		$groups = $query->exec()->fetchAll();

		static::$checkedGroups[$name] = !empty($groups);

		return static::$checkedGroups[$name];
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getGroupType(int $groupId): ?Type
	{
		if (isset(static::$groupTypes[$groupId]))
		{
			return static::$groupTypes[$groupId];
		}

		$this->loadGroupTypes($groupId);

		return static::$groupTypes[$groupId] ?? null;
	}

	/**
	 * Returns whether the container is a new Project; missing containers return false.
	 *
	 * @see Workgroup::isCollabProject() the only definition of the predicate
	 *
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function isCollabProject(int $groupId): bool
	{
		if ($groupId <= 0)
		{
			return false;
		}

		return $this->getGroupType($groupId) === Type::Collab
			&& (static::$collabProjects[$groupId] ?? false);
	}

	public function invalidate(int $groupId): void
	{
		unset(
			static::$groupTypes[$groupId],
			static::$collabProjects[$groupId],
		);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function loadGroupTypes(int ...$groupIds): void
	{
		$uncachedGroups = [];
		foreach ($groupIds as $groupId)
		{
			if (!isset(static::$groupTypes[$groupId]))
			{
				$uncachedGroups[] = $groupId;
			}
		}

		if (empty($uncachedGroups))
		{
			return;
		}

		$groups = WorkgroupTable::query()
			->setSelect(['ID', 'TYPE', 'SCRUM_MASTER_ID', 'PROJECT'])
			->setFilter(['ID' => $uncachedGroups])
			->fetchAll()
		;

		foreach ($groups as $group)
		{
			static::$groupTypes[$group['ID']] = $this->getTypeByFields($group);
			static::$collabProjects[$group['ID']] = (new Workgroup($group))->isCollabProject();
		}
	}

	public function onObjectLoaded(GroupLoadedEvent $event): void
	{
		$group = $event->getGroup();
		$type = $group->getType();

		if ($type !== null)
		{
			static::$groupTypes[$group->getId()] = $type;
		}

		static::$collabProjects[$group->getId()] = $group->isCollabProject();
	}

	private function getTypeByFields(array $fields): ?Type
	{
		if (isset($fields['TYPE']))
		{
			return Type::tryFrom($fields['TYPE']);
		}

		// The marker counts only while it is positive, the way the scrum grid reads it
		// (ScrumRepository::applyTypeFilter()): a stored zero puts the row in no scrum list, so it must not
		// be classified as a scrum here either
		if ((int)($fields['SCRUM_MASTER_ID'] ?? 0) > 0)
		{
			return Type::Scrum;
		}

		if (($fields['PROJECT'] ?? 'N') === 'Y')
		{
			return Type::Project;
		}

		return Type::getDefault();
	}
}
