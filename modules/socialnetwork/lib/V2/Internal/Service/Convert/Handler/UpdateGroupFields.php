<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Collab\Control\Command\ValueObject\CollabSiteIds;
use Bitrix\Socialnetwork\Collab\Integration\Extranet\Extranet;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
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
			$this->ensureAlreadyPersistedAsCollab($group);

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
			$fieldsToUpdate['PROJECT'] = 'Y';

			$siteIds = $this->resolveSiteIds($group);
			if ($siteIds !== null)
			{
				$fieldsToUpdate['SITE_ID'] = $siteIds;
			}
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

	private function resolveSiteIds(Workgroup $group): ?array
	{
		$linkedSiteIds = $this->normalizeSiteIds($group->getSiteIds());
		$resolvedSiteIds = $this->normalizeSiteIds($this->getStoredSiteIds($group->getId()));

		if (empty($resolvedSiteIds))
		{
			$targetSiteIds = $this->normalizeSiteIds($this->getDefaultSiteIds());
		}
		else
		{
			$targetSiteIds = $resolvedSiteIds;
			$extranetSiteId = $this->getExtranetSiteId();

			if ($extranetSiteId !== '' && !in_array($extranetSiteId, $targetSiteIds, true))
			{
				$targetSiteIds[] = $extranetSiteId;
			}
		}

		if (empty($targetSiteIds))
		{
			return null;
		}

		if (!empty($linkedSiteIds) && $this->isSameSiteSet($linkedSiteIds, $targetSiteIds))
		{
			return null;
		}

		return $targetSiteIds;
	}

	/**
	 * @return string[]
	 */
	private function normalizeSiteIds(array $siteIds): array
	{
		return array_values(array_unique(array_filter(
			$siteIds,
			static fn (mixed $siteId): bool => is_string($siteId) && $siteId !== '',
		)));
	}

	private function isSameSiteSet(array $first, array $second): bool
	{
		sort($first);
		sort($second);

		return $first === $second;
	}

	/**
	 * @return string[]
	 */
	protected function getStoredSiteIds(int $groupId): array
	{
		return Container::getInstance()->getProjectRepository()->getSiteIds($groupId);
	}

	protected function getExtranetSiteId(): string
	{
		return Extranet::getSiteId();
	}

	/**
	 * @return string[]
	 */
	protected function getDefaultSiteIds(): array
	{
		return CollabSiteIds::createWithDefaultValue()->getValue();
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

	// An empty field set means the group object already looks like a collab, the stored state has the last word
	private function ensureAlreadyPersistedAsCollab(Workgroup $group): void
	{
		$groupId = $group->getId();

		$persistedFields = $this->getPersistedGroupFields($groupId);
		if ($this->isPersistedAsCollab($persistedFields))
		{
			return;
		}

		throw new ProjectUpdateFieldsException(sprintf(
			'Group %d is not stored as a collab (type [%s], project [%s]), nothing to update',
			$groupId,
			$persistedFields['TYPE'] ?? 'none',
			$persistedFields['PROJECT'] ?? 'none',
		));
	}

	// Every write is verified, not only the one carrying the type: a group whose type is already collab
	// reaches this with the project flag alone, and an unverified flag leaves the same split container
	// the type itself would
	private function ensureCollabWasPersisted(int $groupId, array $fields): void
	{
		for ($attempt = 1; $attempt <= self::CONVERT_TO_COLLAB_MAX_ATTEMPTS; $attempt++)
		{
			if ($this->isPersistedAsCollab($this->getPersistedGroupFields($groupId)))
			{
				$this->invalidateGroupCache($groupId);

				return;
			}

			if (
				$attempt === self::CONVERT_TO_COLLAB_MAX_ATTEMPTS
				|| !$this->canRetryFixInvariant($fields)
			)
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

	// Repeating a set which carries neither the type nor the project flag cannot bring the group any
	// closer to a collab, and CSocNetGroup::Update() is no dry run - it raises events, reindexes the
	// group and syncs the chat on every call
	private function canRetryFixInvariant(array $fields): bool
	{
		return isset($fields['TYPE']) || isset($fields['PROJECT']);
	}

	private function isPersistedAsCollab(?array $fields): bool
	{
		return ($fields['TYPE'] ?? null) === Type::Collab->value
			&& (string)($fields['PROJECT'] ?? 'N') === 'Y'
		;
	}
}
