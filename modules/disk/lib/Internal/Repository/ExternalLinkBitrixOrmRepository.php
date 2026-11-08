<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Repository\Interface\ExternalLinkRepositoryInterface;
use Bitrix\Disk\Internals\ExternalLinkTable;

class ExternalLinkBitrixOrmRepository implements ExternalLinkRepositoryInterface
{
	/**
	 * {@inheritDoc}
	 */
	public function getForUnifiedLinkAccessCheck(mixed $objectId): ?ExternalLink
	{
		return $this->findInternal(
			objectId: $objectId,
			type: ExternalLinkTable::TYPE_MANUAL,
			isExpired: false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getForUnifiedLinkAccessCheckCollection(array $objectIds): array
	{
		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds))));

		if (empty($objectIds))
		{
			return [];
		}

		$links = [];

		$linkList = ExternalLink::getModelList([
			'filter' => [
				'@OBJECT_ID' => $objectIds,
				'=TYPE' => ExternalLinkTable::TYPE_MANUAL,
				'=IS_EXPIRED' => false,
			],
			'order' => [
				'CREATE_TIME' => 'DESC',
			],
		]);

		foreach ($linkList as $link)
		{
			$objectId = (int)$link->getObjectId();

			if (!isset($links[$objectId]))
			{
				$links[$objectId] = $link;
			}
		}

		return $links;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getForComponent(mixed $objectId): ?ExternalLink
	{
		return $this->findInternal(
			objectId: $objectId,
			type: ExternalLinkTable::TYPE_MANUAL,
			isExpired: false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getForComponentByHash(string $hash): ?ExternalLink
	{
		return $this->findInternal(hash: $hash);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getForUse(mixed $objectId): ?ExternalLink
	{
		return $this->findInternal(
			objectId: $objectId,
			type: ExternalLinkTable::TYPE_MANUAL,
			isExpired: false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getAllForUse(mixed $objectId): array
	{
		return $this->listInternal(
			objectId: $objectId,
			type: ExternalLinkTable::TYPE_MANUAL,
			isExpired: false,
		);
	}

	/**
	 * @param mixed $objectId
	 * @param string|null $hash
	 * @param int|null $type
	 * @param bool|null $isExpired
	 * @return ExternalLink|null
	 */
	protected function findInternal(
		mixed $objectId = null,
		?string $hash = null,
		?int $type = null,
		?bool $isExpired = null,
	): ?ExternalLink
	{
		$list = $this->listInternal(
			objectId: $objectId,
			hash: $hash,
			type: $type,
			isExpired: $isExpired,
			limit: 1,
		);

		return $list[0] ?? null;
	}

	/**
	 * @param mixed $objectId
	 * @param string|null $hash
	 * @param int|null $type
	 * @param bool|null $isExpired
	 * @param int|null $limit
	 * @return array<int, ExternalLink>
	 */
	protected function listInternal(
		mixed $objectId = null,
		?string $hash = null,
		?int $type = null,
		?bool $isExpired = null,
		?int $limit = null,
	): array
	{
		$parameters = [
			// Rows born within one second tie on CREATE_TIME, so the id keeps the choice deterministic.
			'order' => [
				'CREATE_TIME' => 'DESC',
				'ID' => 'DESC',
			],
		];

		if ($objectId)
		{
			$parameters['filter']['OBJECT_ID'] = $objectId;
		}

		if (is_string($hash))
		{
			$parameters['filter']['=HASH'] = $hash;
		}

		if (is_int($type))
		{
			$parameters['filter']['TYPE'] = $type;
		}

		if (is_bool($isExpired))
		{
			$parameters['filter']['IS_EXPIRED'] = $isExpired;
		}

		if (is_int($limit))
		{
			$parameters['limit'] = $limit;
		}

		return ExternalLink::getModelList($parameters);
	}
}
