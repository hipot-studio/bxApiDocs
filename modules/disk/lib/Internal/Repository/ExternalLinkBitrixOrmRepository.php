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
			'order' => [
				'CREATE_TIME' => 'DESC',
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
