<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Communications;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Entity\Summary\TruncationReason;
use Bitrix\Main\Type\DateTime;

/**
 * The conversations of every source of the history, merged into one list the freshest first.
 *
 * Every source is read apart ({@see CommunicationActivityFinder}), and one conversation is bound to
 * several of them at once: a call of a deal is a call of the company and of the contact of that deal
 * too, so it arrives once from every source it is bound to. Dropping the repeats happens before the
 * sorting and before the ceiling of the answer is applied - a conversation told twice would otherwise
 * take two places of the ceiling and be told to an agent as two conversations.
 *
 * Which one is the later is decided here and not by the order the storage answered in, by the key
 * {@see ConversationOrder} holds for everyone who orders conversations.
 *
 * Not to be taken for {@see \Bitrix\Crm\V2\Internal\Service\Summary\CommunicationsReader}, which
 * reads the same conversations for the summary of a client: that one counts them by kind and answers a
 * section of the summary, this one names them one by one and answers the history of the tool.
 *
 * @internal
 */
class CommunicationActivityReader
{
	private readonly CommunicationActivityFinder $finder;

	public function __construct(?CommunicationActivityFinder $finder = null)
	{
		$this->finder = $finder ?? new CommunicationActivityFinder();
	}

	/**
	 * Being the whole history travels with the list and is lost by nobody on the way: a source read to
	 * the depth of the walk makes the merged history incomplete however whole the other sources are,
	 * because the conversations left below that one source are missing from the answer all the same.
	 *
	 * @param ItemIdentifier[] $sources The item of the history and the deals of it, already filtered
	 * by the rights of the user.
	 * @param int $perSource Conversations a single source is read of, applied before the merge.
	 * @param int $limit Conversations the merged list carries.
	 * @return Conversations The freshest conversation first, every conversation once, each of them
	 * shaped `array{ID: int, HAPPENED_AT: DateTime|null}` and more.
	 */
	public function read(array $sources, int $perSource, int $limit): Conversations
	{
		$items = [];
		$isCeilingOfASourceHit = false;
		$isDepthOfAReadHit = false;
		foreach ($sources as $source)
		{
			$conversations = $this->finder->findBySource($source, $perSource);
			$isCeilingOfASourceHit = $isCeilingOfASourceHit
				|| $conversations->truncationReason === TruncationReason::ItemLimit
			;
			$isDepthOfAReadHit = $isDepthOfAReadHit
				|| $conversations->truncationReason === TruncationReason::ReadDepth
			;

			foreach ($conversations->items as $item)
			{
				$items[(int)$item['ID']] ??= $item;
			}
		}

		$merged = ConversationOrder::freshestFirst(array_values($items));
		if ($isCeilingOfASourceHit || count($merged) > $limit)
		{
			return Conversations::cutByTheCeiling(array_slice($merged, 0, $limit));
		}

		return $isDepthOfAReadHit ? Conversations::cutByTheDepthOfTheRead($merged) : Conversations::whole($merged);
	}
}
