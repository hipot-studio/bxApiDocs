<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Intranet;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserGroupTable;
use Bitrix\Mobile\Internal\Integration\ServiceFactory;

/**
 * Integration layer for the roles of the portal. All other classes of the shared facts must use
 * this class instead of asking the portal settings or the user groups directly.
 *
 * Two roles with two definitions:
 * - the first administrator is the creator the settings of the cloud name - the same source
 *   Intranet\Service\UserService::isFirstAdmin() compares with, and the nearest approximation of
 *   whoever created the portal: after a transfer of the role it is somebody else;
 * - the current administrators are the members of the administrators group whose membership is in
 *   force, read from the database in any contour and never from a session, which would answer for
 *   the signed-in user only and with the groups of his sign-in.
 */
class PortalRoleReader
{
	public const CHUNK_SIZE = 500;
	private const ADMINISTRATORS_GROUP_ID = 1;
	private ?object $creatorSettings;
	private readonly ServiceFactory $services;

	public function __construct(?object $creatorSettings = null, ?ServiceFactory $services = null)
	{
		$this->creatorSettings = $creatorSettings;
		$this->services = $services ?? new ServiceFactory();
	}

	/**
	 * Null outside the cloud, where there are no settings to name the creator, and while they name
	 * nobody; settings that fail to answer throw what they met, since a failure is not an unknown.
	 */
	public function getCreatorId(): ?int
	{
		if (!$this->isCloud())
		{
			return null;
		}

		$creatorId = $this->readCreatorId();

		return $creatorId > 0 ? $creatorId : null;
	}

	/**
	 * Those of the users who are members of the administrators group whose membership has started and not
	 * yet ended at the moment, in one read of the group per portion of CHUNK_SIZE users. A group that cannot
	 * be read throws what it met.
	 *
	 * @param int[] $userIds
	 * @return int[]|null
	 */
	public function getAdminIds(array $userIds, DateTime $now): ?array
	{
		$adminIds = [];
		foreach (array_chunk($userIds, self::CHUNK_SIZE) as $chunk)
		{
			foreach ($this->createAdminsQuery($chunk, $now)->fetchAll() as $row)
			{
				$adminIds[] = (int)$row['USER_ID'];
			}
		}

		return array_values(array_unique($adminIds));
	}

	protected function isCloud(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	/**
	 * The creator the settings of the cloud name, 0 while there are no settings to ask.
	 */
	protected function readCreatorId(): int
	{
		$this->creatorSettings ??= $this->services->creatorSettings();

		return $this->creatorSettings?->getCreatorId() ?? 0;
	}

	/**
	 * @param int[] $userIds
	 */
	protected function createAdminsQuery(array $userIds, DateTime $now): Query
	{
		return UserGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', self::ADMINISTRATORS_GROUP_ID)
			->whereIn('USER_ID', $userIds)
			->where(Query::filter()
				->logic('or')
				->whereNull('DATE_ACTIVE_FROM')
				->where('DATE_ACTIVE_FROM', '<=', $now),
			)
			->where(Query::filter()
				->logic('or')
				->whereNull('DATE_ACTIVE_TO')
				->where('DATE_ACTIVE_TO', '>=', $now),
			)
		;
	}
}
