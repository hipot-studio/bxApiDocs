<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Communications;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * The conversations of one source: one read of the activities of the providers the speech analysis
 * writes a summary of.
 *
 * The providers are taken from the operation itself and not listed here a second time - a kind added
 * to it becomes a kind of the history without a change of this class, and a kind dropped from it stops
 * being one. The provider is the whole of the condition: an activity of one of those kinds is a
 * conversation of the card, whether the telephony left an origin on it, whether it carries a text of
 * its own and whether the analysis ever got to it. A call a manager typed into the card by hand has
 * none of those marks and is a conversation all the same.
 *
 * A conversation belongs to the source by its bindings and not by the owner written on it: an activity
 * carries one owner and any number of bindings, and a call of a deal is bound to the company and to the
 * contact of that deal as well. Asked by the owner, a company answers only the conversations nobody
 * bound elsewhere and loses the calls its own people had inside the deals.
 *
 * The rights of the user have been applied to the source by the caller, so the query asks for none of
 * its own.
 *
 * The source is read from its newest binding backwards and never whole. Ordering the conversations of
 * a card by {@see self::HAPPENED_AT} is an expression no index serves and none can be made to serve, so
 * a read that asked the storage for the order of the whole source would have the storage read every
 * activity of the card and sort all of them to answer thirty. Instead a step takes a bounded handful of
 * the newest bindings, which is a walk of an index, and the order is decided over that handful.
 *
 * @internal
 */
class CommunicationActivityFinder
{
	/**
	 * When the conversation happened, which the rest of the history orders by and tells about.
	 *
	 * It is not the moment the row appeared: a letter pulled in by the mail sync carries the date it was
	 * written in `START_TIME` and the moment of the import in `CREATED`, because
	 * {@see \CCrmActivity::Add()} writes the current time into `CREATED` unless the caller asks it to
	 * keep the one it was given, and the mail does not ask. Ordered by `CREATED`, a year-old letter read
	 * today comes back as the freshest conversation of the card, is told about with today's date and
	 * pushes a call that really was the last one out of the ceiling of the answer.
	 *
	 * `CREATED` stays as the fallback and not as a nicety: the column is nullable, and a row that
	 * reached the table without a moment of its own would otherwise sort as the oldest conversation
	 * there is and be told about with an empty date.
	 */
	public const HAPPENED_AT = 'HAPPENED_AT';

	/**
	 * Exactly what the assembly of a record reads: the provider says which kind of conversation it was,
	 * the direction and the type of the provider decide which way it went, the origin names the call of
	 * the telephony behind it and the responsible one names the employee.
	 *
	 * The text of the card is not among them. The column is a `longtext`, the read brings back as many
	 * rows as a source is allowed, and neither the answer of the tool nor the condition of the query
	 * needs a character of it.
	 */
	private const SELECT = [
		'ID',
		'OWNER_TYPE_ID',
		'OWNER_ID',
		self::HAPPENED_AT,
		'DIRECTION',
		'PROVIDER_ID',
		'PROVIDER_TYPE_ID',
		'ORIGIN_ID',
		'RESPONSIBLE_ID',
	];

	/**
	 * Bindings of the source one step of the walk takes.
	 *
	 * The number is a reserve over the ceiling of the answer and not a ceiling of its own: a binding is
	 * any activity of the card and only some of those are conversations, so a step has to be deep enough
	 * that the conversations inside it fill the answer on a card where they are the minority. Thirty is
	 * the most the answer ever carries ({@see CommunicationHistoryLimits::MAX_ITEMS_LIMIT}), and two
	 * hundred holds it as long as better than one activity of the card in seven is a conversation.
	 */
	private const BINDINGS_PER_STEP = 200;

	/**
	 * Steps the walk is allowed before it answers with what it found.
	 *
	 * The ceiling makes the read bounded, and a bounded read of an unbounded history is an approximate
	 * one: a card whose six hundred newest activities hold fewer conversations than the answer has room
	 * for is told about with fewer, and the conversations below that depth are not reached at all. The
	 * alternative is the read this replaced, which sorted the whole history of the card to answer thirty
	 * rows and grew without limit with the card.
	 */
	private const BINDING_STEPS = 3;

	/**
	 * The walk goes by the identifier of the activity and the answer is ordered by
	 * {@see self::HAPPENED_AT}, and the two agree on everything a card is normally made of. They part on
	 * a conversation whose moment is far from the moment its row appeared - a letter of last year pulled
	 * in by the mail sync today - which the walk reaches as a new binding and the order places among the
	 * old conversations. Such a row can push a conversation out of the answer it would have kept, and a
	 * conversation older than the depth of the walk cannot enter the answer at all.
	 *
	 * Whether what came back is the whole of the source is answered along with it, and it has to be: a
	 * card busy with to-do items and meetings keeps its conversations below the depth of the walk, and
	 * the four conversations such a card answers look exactly like the whole history of a quiet client.
	 * Told apart, the first is read as the recent part of a history and the second as a fact.
	 *
	 * @param int $limit Conversations of this source the read is allowed.
	 * @return Conversations The conversations of the source, the freshest first, each of them shaped
	 * `array{ID: int, OWNER_TYPE_ID: int, OWNER_ID: int, HAPPENED_AT: DateTime|null, DIRECTION: int|null,
	 * PROVIDER_ID: string|null, PROVIDER_TYPE_ID: string|null, ORIGIN_ID: string|null,
	 * RESPONSIBLE_ID: int|null}`.
	 */
	public function findBySource(ItemIdentifier $source, int $limit): Conversations
	{
		$found = [];
		$lastBinding = null;
		$isCardStillGoing = false;
		for ($step = 0; $step < self::BINDING_STEPS; $step++)
		{
			$activityIds = $this->boundTo($source, $lastBinding);
			if ($activityIds === [])
			{
				// a step with nothing in it is the end of the card, whatever the step before it held
				$isCardStillGoing = false;

				break;
			}

			foreach ($this->conversationsAmong($activityIds) as $conversation)
			{
				$found[(int)$conversation['ID']] = $conversation;
			}

			$lastBinding = (int)end($activityIds);
			$isCardStillGoing = count($activityIds) >= self::BINDINGS_PER_STEP;
			if (count($found) >= $limit || !$isCardStillGoing)
			{
				break;
			}
		}

		return self::answerOf(ConversationOrder::freshestFirst(array_values($found)), $limit, $isCardStillGoing);
	}

	/**
	 * Which of the two bounds of the read, if either, left something behind.
	 *
	 * The ceiling is named first: it has a tail of its own to show for itself, while the bindings left
	 * below the walk hold conversations only on a card that has them there. Both at once is the busy
	 * card of a call centre, and what the ceiling dropped is the nearer loss - the caller raises its own
	 * number and gets those rows, while the depth of the walk is not its to move.
	 *
	 * An answer filled to the ceiling is the ceiling whether the walk had a tail to drop or came to rest
	 * exactly on it: either way the walk stopped because the answer was full, and the rest of the card is
	 * a bigger number away. The depth is left for the answer that did not fill up and still has a card
	 * going on below it - the one place where asking for more buys nothing.
	 *
	 * A step that came back short is the end of the card, so the conversations above it are the whole of
	 * it however few they are - a card that ended with exactly the ceiling filled included, since there
	 * is no tail of it to ask for.
	 *
	 * @param array<int, array> $conversations Ordered, not yet cut.
	 */
	private static function answerOf(array $conversations, int $limit, bool $isCardStillGoing): Conversations
	{
		$isCeilingHit = count($conversations) > $limit
			|| (count($conversations) === $limit && $isCardStillGoing)
		;
		if ($isCeilingHit)
		{
			return Conversations::cutByTheCeiling(array_slice($conversations, 0, $limit));
		}

		return $isCardStillGoing
			? Conversations::cutByTheDepthOfTheRead($conversations)
			: Conversations::whole($conversations)
		;
	}

	/**
	 * The conversations among a handful of activities named by their primary key: the rows are reached
	 * one by one through that key, so the read is as big as the handful and never as big as the history
	 * of the card.
	 *
	 * Neither the order nor the ceiling is asked of the storage here. Ordering by
	 * {@see self::HAPPENED_AT} is an expression no index serves, so the storage would sort whatever it
	 * found, and what it found is already bounded by the handful - the same sort costs nothing in PHP,
	 * where the merge of the sources performs it anyway.
	 *
	 * @param int[] $activityIds
	 * @return array<int, array>
	 */
	protected function conversationsAmong(array $activityIds): array
	{
		return self::conversationsQuery($activityIds)->fetchAll();
	}

	/**
	 * @param int[] $activityIds
	 */
	private static function conversationsQuery(array $activityIds): Query
	{
		return ActivityTable::query()
			->registerRuntimeField(self::happenedAt())
			->setSelect(self::SELECT)
			->whereIn('ID', $activityIds)
			->whereIn('PROVIDER_ID', SummarizeCallTranscription::SUPPORTED_ACTIVITY_PROVIDER_IDS)
		;
	}

	/**
	 * The moment of a conversation as one field, so that the read, the merge of the sources and the
	 * record of the answer all speak of the same one and cannot drift apart. {@see self::HAPPENED_AT}
	 * says why it is not `CREATED`.
	 */
	private static function happenedAt(): ExpressionField
	{
		return new ExpressionField(
			self::HAPPENED_AT,
			'COALESCE(%s, %s)',
			['START_TIME', 'CREATED'],
			['data_type' => 'datetime'],
		);
	}

	/**
	 * The newest activities bound to the source, walked by the binding and not joined to it: a join
	 * answers one activity as many times as it is bound to the source, and the repeats would eat the
	 * ceiling of the read before the merge ever sees them.
	 *
	 * The condition is the whole of `IX_B_CRM_ACT_BIND_2`, the identifier of the activity included, so
	 * the step is a backward walk of that index and stops after the rows it asked for. This is the one
	 * place of the read that is allowed to be ordered by the storage: the identifier is the last column
	 * of the index, and ordering by it costs no sort.
	 *
	 * @param int|null $before The oldest binding of the previous step, so a step continues the walk
	 * instead of reading it again.
	 * @return int[] The activities, the newest binding first.
	 */
	protected function boundTo(ItemIdentifier $source, ?int $before): array
	{
		return array_map(
			static fn (array $row): int => (int)$row['ACTIVITY_ID'],
			self::bindingsQuery($source, $before)->fetchAll(),
		);
	}

	private static function bindingsQuery(ItemIdentifier $source, ?int $before): Query
	{
		$query = ActivityBindingTable::query()
			->setSelect(['ACTIVITY_ID'])
			->where('OWNER_TYPE_ID', $source->getEntityTypeId())
			->where('OWNER_ID', $source->getEntityId())
			->setOrder(['ACTIVITY_ID' => 'DESC'])
			->setLimit(self::BINDINGS_PER_STEP)
		;
		if ($before !== null)
		{
			$query->where('ACTIVITY_ID', '<', $before);
		}

		return $query;
	}
}
