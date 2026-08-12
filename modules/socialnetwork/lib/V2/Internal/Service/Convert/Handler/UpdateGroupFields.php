<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Collab\Control\Command\ValueObject\CollabSiteIds;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectUpdateFieldsException;
use Bitrix\Socialnetwork\WorkgroupTable;
use CSocNetGroup;

class UpdateGroupFields implements HandlerInterface
{
	private const CONVERT_TO_COLLAB_MAX_ATTEMPTS = 2;

	/**
	 * @throws ProjectUpdateFieldsException
	 */
	public function __invoke(Workgroup $group): void
	{
		$fields = $this->prepareFields($group);
		if (empty($fields))
		{
			return;
		}

		$updateResult = $this->updateGroup($group->getId(), $fields);
		if ($updateResult === false)
		{
			throw new ProjectUpdateFieldsException('Unable to update group fields');
		}

		$this->ensureCollabWasPersisted($group->getId(), $fields);
	}

	protected function prepareFields(Workgroup $group): array
	{
		$fieldsToUpdate = [];

		$newOpened = $group->isOpened() && $group->isVisible();
		if ($group->isOpened() !== $newOpened)
		{
			$fieldsToUpdate['OPENED'] = $newOpened ? 'Y' : 'N';
		}

		if ($group->isVisible() !== $newOpened)
		{
			$fieldsToUpdate['VISIBLE'] = $newOpened ? 'Y' : 'N';
		}

		if ($group->getType() !== Type::Collab)
		{
			$fieldsToUpdate['TYPE'] = Type::Collab->value;
			$fieldsToUpdate['SITE_ID'] = CollabSiteIds::createWithDefaultValue()->getValue();
			$fieldsToUpdate['PROJECT'] = 'Y';
		}

		if (
			($fieldsToUpdate['TYPE'] ?? null) !== Type::Collab->value
			&& (string)($group->getFields()['PROJECT'] ?? 'N') !== 'Y'
		)
		{
			$fieldsToUpdate['PROJECT'] = 'Y';
		}

		return $fieldsToUpdate;
	}

	protected function updateGroup(int $groupId, array $fields): int|bool
	{
		return CSocNetGroup::Update($groupId, $fields);
	}

	protected function getPersistedGroupFields(int $groupId): ?array
	{
		$fields = WorkgroupTable::getList([
			'select' => ['ID', 'TYPE', 'PROJECT'],
			'filter' => ['=ID' => $groupId],
			'limit' => 1,
		])->fetch();

		return is_array($fields) ? $fields : null;
	}

	protected function invalidateGroupCache(int $groupId): void
	{
		GroupRegistry::getInstance()->invalidate($groupId);
	}

	private function ensureCollabWasPersisted(int $groupId, array $fields): void
	{
		if (($fields['TYPE'] ?? null) !== Type::Collab->value)
		{
			return;
		}

		for ($attempt = 1; $attempt <= self::CONVERT_TO_COLLAB_MAX_ATTEMPTS; $attempt++)
		{
			if ($this->isPersistedAsCollab($this->getPersistedGroupFields($groupId)))
			{
				$this->invalidateGroupCache($groupId);

				return;
			}

			if ($attempt === self::CONVERT_TO_COLLAB_MAX_ATTEMPTS)
			{
				break;
			}

			$updateResult = $this->updateGroup($groupId, $fields);
			if ($updateResult === false)
			{
				throw new ProjectUpdateFieldsException('Unable to update group fields');
			}
		}

		throw new ProjectUpdateFieldsException(sprintf('Group %d was not converted to collab', $groupId));
	}

	private function isPersistedAsCollab(?array $fields): bool
	{
		return ($fields['TYPE'] ?? null) === Type::Collab->value
			&& (string)($fields['PROJECT'] ?? 'N') === 'Y'
		;
	}
}
