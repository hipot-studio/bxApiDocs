<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\Query;

use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Recent\Recent;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * ALG-02 with the "show colleagues" switches off: a private row whose own last message is the system
 * "joined the portal" one leaves the feed, as it does in the old list (im/lib/recent.php:1209-1215).
 *
 * The condition lives in the query and not over the fetched page: a row removed after the page is cut
 * would leave the answer shorter than the limit, and a fully removed page would leave the client with
 * no date to build the next cursor from. The old list selects the same param through the same join.
 */
readonly class JoinedPortalRowFilter
{
	private const JOIN_CODE = 'JOIN_CODE';

	public function apply(Query $query): void
	{
		$query->registerRuntimeField($this->getJoinCodeReference());

		$query->where(
			Query::filter()
				->logic(ConditionTree::LOGIC_OR)
				->whereNot('ITEM_TYPE', Chat::IM_TYPE_PRIVATE)
				->whereNull(self::JOIN_CODE . '.MESSAGE_ID')
		);
	}

	/**
	 * Matched on ITEM_MID - the row's own last message, the one the marker is read from as well. Both
	 * param columns are in the ON clause, so the join is the (MESSAGE_ID, PARAM_NAME) index lookup and
	 * can add no second row to a recent row.
	 */
	private function getJoinCodeReference(): Reference
	{
		$on = Join::on('this.ITEM_MID', 'ref.MESSAGE_ID')
			->where('ref.PARAM_NAME', Params::CODE)
			->where('ref.PARAM_VALUE', Recent::MESSAGE_CODE_USER_JOIN)
		;

		return (new Reference(self::JOIN_CODE, MessageParamTable::class, $on))
			->configureJoinType(Join::TYPE_LEFT)
		;
	}
}
