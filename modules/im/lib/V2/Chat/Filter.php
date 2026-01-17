<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\Model\ChatIndexTable;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Permission\ActionGroup;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Search\Content;

/**
 * Modifies query by permissions or fields
 */
class Filter
{
	protected Query $query;

	public function __construct()
	{
		$this->query = ChatTable::query()->setSelect(['ID']);
	}

	public static function init(): self
	{
		return new self();
	}

	/**
	 * @param array<int> $ids
	 * @return $this
	 */
	public function filterByIds(array $ids): self
	{
		$this->query->whereIn('ID', $ids);

		return $this;
	}

	/**
	 * @param array<string> $types - types from Chat::IM_TYPE_
	 * @return $this
	 */
	public function filterByTypes(array $types): self
	{
		$this->query->whereIn('TYPE', $types);

		return $this;
	}

	public function filterByPermissions(int $userId, ActionGroup $action): self
	{
		$this->query
			->registerRuntimeField(new Reference(
					'RELATION',
					RelationTable::class,
					Join::on('this.ID', 'ref.CHAT_ID')
						->where('ref.USER_ID', $userId),
					['join_type' => Join::TYPE_LEFT]
				)
			)
		;

		\Bitrix\Im\V2\Permission\Filter::getRoleOrmFilter($this->query, $action, 'RELATION', '');

		return $this;
	}

	public function filterByEntityType(?array $types): self
	{
		if ($types)
		{
			$this->query->whereIn('ENTITY_TYPE', $types);
		}
		else
		{
			$this->query->where(Query::filter()
				->logic('or')
				->whereNull('ENTITY_TYPE')
				->where('ENTITY_TYPE', ''))
			;
		}

		return $this;
	}

	public function filterByName(string $searchString): self
	{
		$searchString = trim($searchString);
		$preparedString = Content::prepareStringToken($searchString);
		$matchString = Helper::matchAgainstWildcard($preparedString);

		if (!Content::canUseFulltextSearch($matchString))
		{
			$this->query->where('ID', 0);
			return $this;
		}

		$this->query->registerRuntimeField(
			'INDEX',
			new Reference(
				'INDEX',
				ChatIndexTable::class,
				Join::on('this.ID', 'ref.CHAT_ID'),
				['join_type' => Join::TYPE_INNER]
			)
		);

		$this->query->whereMatch('INDEX.SEARCH_TITLE', $matchString);

		return $this;
	}

	public function filterUserIsMember(int $userId): self
	{
		if ($userId > 0)
		{
			$this->query->registerRuntimeField(
				'MEMBER_RELATION',
				new Reference(
					'MEMBER_RELATION',
					RelationTable::class,
					Join::on('this.ID', 'ref.CHAT_ID')->where('ref.USER_ID', $userId),
					['join_type' => Join::TYPE_INNER]
				)
			);
		}

		return $this;
	}

	public function setLimit(int $limit): self
	{
		if ($limit > 0)
		{
			$this->query->setLimit($limit);
		}

		return $this;
	}

	/**
	 * @return array<int> - filtered ids
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getIds(): array
	{
		return array_map(fn($chat) => $chat['ID'], $this->query->fetchAll());
	}

	/**
	 * @return array<int, array{ID: int, TITLE: string}>
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getTitles(): array
	{
		$this->query->setSelect(['ID', 'TITLE']);
		return $this->query->fetchAll();
	}
}
