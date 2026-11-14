<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\SharingTargetDto;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\RightsManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;

/**
 * Explicit rights granted beyond the base rights of the storage, of a page of objects and of their
 * ancestors, read in one statement per batch over the closure of paths.
 *
 * Only positive rights count, the root of a storage does not (its rights are the base rights of the
 * storage), and neither does the code of the creator. The rights of the object itself are its
 * targets; the rights of an ancestor are the fact of inherited sharing alone, so every ancestor
 * collapses into one row per object in the statement.
 */
final class SharingTargetReader implements SharingTargetSource
{
	private const IDS_PER_QUERY = 300;

	private const RIGHT = 'SYNC_SHARING_RIGHT';
	private const HOLDER = 'SYNC_SHARING_HOLDER';

	/**
	 * @param int[] $realObjectIds
	 * @return array<int, array{direct: list<array{type: string, id: ?int, bySharing: bool}>, inherited: bool}>
	 *         keyed by the object; an object without explicit rights on itself or its ancestors is absent
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function read(array $realObjectIds): array
	{
		$realObjectIds = array_values(array_unique(array_filter(array_map('intval', $realObjectIds))));
		if ($realObjectIds === [])
		{
			return [];
		}

		$rows = [];
		foreach (array_chunk($realObjectIds, self::IDS_PER_QUERY) as $chunk)
		{
			array_push($rows, ...$this->readChunk($chunk, false));
		}

		$result = [];
		foreach ($rows as $row)
		{
			$objectId = (int)$row['OBJECT_ID'];
			$result[$objectId] ??= ['direct' => [], 'inherited' => false];

			if ($row['OWN_CODE'] === null)
			{
				$result[$objectId]['inherited'] = true;

				continue;
			}

			$result[$objectId]['direct'][] = self::targetOf((string)$row['OWN_CODE'], (string)$row['OWN_DOMAIN']);
		}

		foreach ($result as &$sharing)
		{
			$sharing['direct'] = self::collapse($sharing['direct']);
		}
		unset($sharing);

		return $result;
	}

	/**
	 * The targets of one object, read the same way as those of a page.
	 *
	 * @return list<array{type: string, id: ?int, bySharing: bool}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function explicit(int $objectId): array
	{
		if ($objectId <= 0)
		{
			return [];
		}

		$targets = [];
		foreach ($this->readChunk([$objectId], true) as $row)
		{
			$targets[] = self::targetOf((string)$row['OWN_CODE'], (string)$row['OWN_DOMAIN']);
		}

		return self::collapse($targets);
	}

	/**
	 * @param int[] $objectIds
	 * @return array<int, array{OBJECT_ID: int|string, OWN_CODE: ?string, OWN_DOMAIN: ?string}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readChunk(array $objectIds, bool $ownOnly): array
	{
		$right = new Reference(self::RIGHT, RightTable::class, Join::on('this.PARENT_ID', 'ref.OBJECT_ID'));
		$right->configureJoinType(Join::TYPE_INNER);

		$holder = new Reference(self::HOLDER, ObjectTable::class, Join::on('this.PARENT_ID', 'ref.ID'));
		$holder->configureJoinType(Join::TYPE_INNER);

		$query = ObjectPathTable::query()
			->registerRuntimeField($right)
			->registerRuntimeField($holder)
			->registerRuntimeField(new ExpressionField(
				'OWN_CODE',
				'CASE WHEN %s = 0 THEN %s END',
				['DEPTH_LEVEL', self::RIGHT . '.ACCESS_CODE'],
			))
			->registerRuntimeField(new ExpressionField(
				'OWN_DOMAIN',
				'CASE WHEN %s = 0 THEN %s END',
				['DEPTH_LEVEL', self::RIGHT . '.DOMAIN'],
			))
			->setSelect(['OBJECT_ID', 'OWN_CODE', 'OWN_DOMAIN'])
			->setDistinct()
			->whereIn('OBJECT_ID', $objectIds)
			->where(self::RIGHT . '.NEGATIVE', false)
			->whereNot(self::RIGHT . '.ACCESS_CODE', AccessCodeEnum::CREATOR->value)
			->whereNotNull(self::HOLDER . '.PARENT_ID')
		;

		if ($ownOnly)
		{
			$query->where('DEPTH_LEVEL', 0);
		}

		return $query->fetchAll();
	}

	/**
	 * @return array{type: string, id: ?int, bySharing: bool}
	 */
	private static function targetOf(string $code, string $domain): array
	{
		[$type, $id] = self::parse($code);

		return [
			'type' => $type,
			'id' => $id,
			'bySharing' => str_starts_with($domain, RightsManager::DOMAIN_SHARING . '-'),
		];
	}

	/**
	 * @return array{0: string, 1: ?int}
	 */
	private static function parse(string $code): array
	{
		$patterns = [
			'/^I?U(\d+)$/' => SharingTargetDto::TARGET_USER,
			'/^SG(\d+)_[AEK]$/' => SharingTargetDto::TARGET_WORKGROUP,
			'/^DR?(\d+)$/' => SharingTargetDto::TARGET_DEPARTMENT,
			'/^CHAT(\d+)$/' => SharingTargetDto::TARGET_CHAT,
		];
		foreach ($patterns as $pattern => $type)
		{
			if (preg_match($pattern, $code, $matches) && (int)$matches[1] > 0)
			{
				return [$type, (int)$matches[1]];
			}
		}

		return match ($code)
		{
			AccessCodeEnum::AUTHORIZED_USER->value => [SharingTargetDto::TARGET_ALL_USERS, null],
			'G2' => [SharingTargetDto::TARGET_EVERYONE, null],
			default => [SharingTargetDto::TARGET_OTHER, null],
		};
	}

	/**
	 * One entry per pair of type and identifier. A pair granted both by sharing and by other rights
	 * does not count as coming from sharing.
	 *
	 * @param list<array{type: string, id: ?int, bySharing: bool}> $targets
	 * @return list<array{type: string, id: ?int, bySharing: bool}>
	 */
	private static function collapse(array $targets): array
	{
		$collapsed = [];
		foreach ($targets as $target)
		{
			$key = $target['type'] . ':' . ($target['id'] ?? '');
			if (isset($collapsed[$key]))
			{
				$collapsed[$key]['bySharing'] = $collapsed[$key]['bySharing'] && $target['bySharing'];

				continue;
			}

			$collapsed[$key] = $target;
		}

		return array_values($collapsed);
	}
}
