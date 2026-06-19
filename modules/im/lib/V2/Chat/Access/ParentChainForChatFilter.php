<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

readonly class ParentChainForChatFilter
{
	/**
	 * @param int[] $ancestorIds
	 */
	public function __construct(
		private string $userIdField,
		private array $ancestorIds,
	) {}

	public function apply(Query $query): void
	{
		foreach (array_values($this->ancestorIds) as $index => $ancestorId)
		{
			$alias = "PARENT_ACCESS_{$index}";
			$query->registerRuntimeField($alias, new Reference(
				$alias,
				RelationTable::class,
				Join::on("this.{$this->userIdField}", 'ref.USER_ID')
					->where('ref.CHAT_ID', $ancestorId),
				['join_type' => Join::TYPE_INNER],
			));
		}
	}
}
