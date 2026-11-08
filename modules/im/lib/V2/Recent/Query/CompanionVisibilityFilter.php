<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\Query;

use Bitrix\Im\Model\UserTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * ALG-03. Conditions about the companion of a private conversation: a disabled colleague with nothing
 * unread leaves the feed, service accounts stay, and an external reader is narrowed to people they
 * share something with. Rows that are not private conversations are left untouched.
 */
readonly class CompanionVisibilityFilter
{
	private const COMPANION = 'COMPANION';

	/** M6: these stay visible even with the account disabled. */
	private const SERVICE_AUTH_IDS = ['bot', 'imconnector', 'replica'];

	public function __construct(
		private ?Query $visibleCompanions = null,
	) {}

	public function apply(Query $query): void
	{
		$query->registerRuntimeField($this->getCompanionReference());

		$query->where(
			Query::filter()
				->logic(ConditionTree::LOGIC_OR)
				->whereNot('ITEM_TYPE', Chat::IM_TYPE_PRIVATE)
				->where($this->getCompanionConditions())
		);
	}

	private function getCompanionConditions(): ConditionTree
	{
		$conditions = Query::filter()->where($this->getLiveAccountCondition());

		if ($this->visibleCompanions !== null)
		{
			$conditions->whereIn(self::COMPANION . '.ID', $this->visibleCompanions);
		}

		return $conditions;
	}

	/**
	 * M8: a conversation with a disabled colleague is hidden while it holds nothing unread. Unread is
	 * read from the row and from message pointers already present in the select, so no counter is
	 * loaded for the sake of the rule.
	 *
	 * A private row whose ITEM_ID resolves to no user is left alone, the way the old list leaves it:
	 * subtraction there lives inside a branch that has already loaded the user (im/lib/recent.php,
	 * formatRow). Such rows do occur - orphans of deleted accounts.
	 */
	private function getLiveAccountCondition(): ConditionTree
	{
		return Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->whereNull(self::COMPANION . '.ID')
			->where(self::COMPANION . '.ACTIVE', true)
			->whereIn(self::COMPANION . '.EXTERNAL_AUTH_ID', self::SERVICE_AUTH_IDS)
			->where('UNREAD', true)
			->whereColumn('ITEM_MID', '>', 'RELATION.LAST_ID')
		;
	}

	private function getCompanionReference(): Reference
	{
		return (new Reference(
			self::COMPANION,
			UserTable::class,
			Join::on('this.ITEM_ID', 'ref.ID')->where('this.ITEM_TYPE', Chat::IM_TYPE_PRIVATE),
		))->configureJoinType(Join::TYPE_LEFT);
	}
}
