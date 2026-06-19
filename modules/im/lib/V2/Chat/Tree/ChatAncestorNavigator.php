<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

final class ChatAncestorNavigator
{
	public const DEFAULT_DEPTH = 2;

	private const ANCESTOR_PREFIX = 'TREE_ANC';

	private \SplObjectStorage $cache;

	public function __construct()
	{
		$this->cache = new \SplObjectStorage();
	}

	/**
	 * @return TreeLevel[] [depth => TreeLevel]
	 * @throws \LogicException when ancestor joins were already registered on this query with different parameters
	 */
	public function joinAncestors(
		Query $query,
		TreeOrigin $origin,
		int $depth,
		string $joinType = Join::TYPE_LEFT,
		bool $joinLastLevel = false,
	): array
	{
		if ($this->cache->offsetExists($query))
		{
			$cached = $this->cache[$query];
			if (
				$cached['depth'] !== $depth
				|| !$cached['origin']->equals($origin)
				|| $cached['joinType'] !== $joinType
				|| $cached['joinLastLevel'] !== $joinLastLevel
			)
			{
				throw new \LogicException('Ancestor joins already registered on this query with different parameters');
			}

			return $cached['levels'];
		}

		$levels = $this->doJoin($query, $origin, $depth, $joinType, $joinLastLevel);

		$this->cache[$query] = [
			'levels' => $levels,
			'origin' => $origin,
			'depth' => $depth,
			'joinType' => $joinType,
			'joinLastLevel' => $joinLastLevel,
		];

		return $levels;
	}

	private function doJoin(
		Query $query,
		TreeOrigin $origin,
		int $depth,
		string $joinType,
		bool $joinLastLevel,
	): array
	{
		$levels = [];
		$levels[0] = new TreeLevel(0, $origin->chatAlias, $origin->idExpression);

		$prevParentRef = $origin->parentRef;
		$joinDepth = $joinLastLevel ? $depth : max($depth - 1, 0);

		for ($level = 1; $level <= $joinDepth; $level++)
		{
			$alias = self::ANCESTOR_PREFIX . "_{$level}";

			$query->registerRuntimeField(
				(new Reference(
					$alias,
					ChatTable::class,
					Join::on("this.{$prevParentRef}", 'ref.ID'),
				))->configureJoinType($joinType)
			);

			$levels[$level] = new TreeLevel($level, $alias, $prevParentRef);
			$prevParentRef = "{$alias}.PARENT_ID";
		}

		if ($depth > $joinDepth)
		{
			$levels[$depth] = new TreeLevel($depth, null, $prevParentRef);
		}

		return $levels;
	}
}
