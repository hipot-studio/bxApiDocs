<?php

declare(strict_types=1);

namespace Bitrix\Call\Service\CallLog;

use Bitrix\Call\Internals\OwnerTerm;
use Bitrix\Call\Model\CallUserLogIndexTable;
use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * The first source of the search of the journal: which rows of the owner carry indexed content - the name of the
 * chat, the companion, the number - that answers the query.
 *
 * It answers with a branch of the condition of the page over the search table of the journal, in one of two shapes
 * chosen by a probe of the rows of that table and by the term of the owner, and guards the shape that carries the
 * term - see self::conditionOf(). The other source, ParticipantSearch, goes through the roster of the call instead;
 * the service asks both of them with the same expression and joins their branches by OR (JournalSearchCondition).
 *
 * Not final: the answer of a fulltext pass is the one thing a test case cannot produce, so the tests stand in for
 * the probe, the threshold of the connection and the guard through the seams marked @internal below.
 */
class JournalIndexSearch
{
	/**
	 * The name the search table is joined to the journal under in the query of the page: the branch this source
	 * answers with asks the columns of that table by it.
	 */
	public const INDEX_REFERENCE = 'CALL_LOG_INDEX';

	private SearchExpression $searchExpression;

	/**
	 * What the probe of the search table answered, for the lifetime of this source; null until it is asked.
	 *
	 * @see self::isOwnerKnownForEveryIndexedRow()
	 */
	private ?bool $ownerKnownForEveryIndexedRow = null;

	/**
	 * What the guard answered, by the expression of the query, for the lifetime of this source: one page asks the
	 * same search for every read of the journal, and the answer does not depend on the owner.
	 *
	 * @see self::doesTheQueryAloneAnswerARow()
	 * @var array<string, bool>
	 */
	private array $queryAloneAnswersARow = [];

	public function __construct()
	{
		$this->searchExpression = new SearchExpression();
	}

	/**
	 * The search table as the query of the page joins it to the journal: by the row of the journal it indexes.
	 *
	 * @param string $joinType Join::TYPE_INNER when this source answers alone, Join::TYPE_LEFT when a branch of
	 *     the other source stands beside its own under OR - a row the table does not index may still be found
	 *     through the people of its call.
	 */
	public static function referenceToTheIndex(string $joinType): Reference
	{
		return new Reference(
			self::INDEX_REFERENCE,
			CallUserLogIndexTable::class,
			Join::on('this.ID', 'ref.USERLOG_ID'),
			['join_type' => $joinType],
		);
	}

	/**
	 * The branch of the condition of the page this source answers for: the rows whose indexed content answers the
	 * query. A condition over the search table joined under self::INDEX_REFERENCE, and not a list of rows - the
	 * owner, the filter, the cursor, the order and the limit of the page are the conditions of the query it is put
	 * into.
	 *
	 * Two shapes, chosen as the list of ids chose them before. The shape of the owner - the term of the owner in the
	 * expression and the owner in the column of the table - is taken only when no row of the table is left without
	 * its owner ({@see self::isOwnerKnownForEveryIndexedRow()}) and the term is long enough for the index to hold it
	 * ({@see self::isTermOfTheOwnerLongEnoughToBeIndexed()}). Otherwise the expression is asked as it is, with no
	 * term and no column: a row the pass has not reached carries neither, and the owner of the journal in the query
	 * of the page is the limit there.
	 *
	 * In the shape of the owner the owner is asked for twice and neither ask is spare. Inside the expression it is a
	 * term of the fulltext pass itself, so the pass answers with the rows of the owner rather than with the matches of
	 * the portal. In the column it is an ordinary predicate, kept as a second and independent limit: the term is a
	 * property of the content of a row and the column is a property of the row, and the isolation of one owner from
	 * another must not rest on the content alone.
	 *
	 * The shape of the owner is guarded: an expression whose words the server dropped is left with the term alone
	 * and would answer with the whole journal of the owner. So the words of the query are first asked of the whole
	 * table on their own ({@see self::doesTheQueryAloneAnswerARow()}); no row answers them - there is no branch.
	 *
	 * @param string $search The query as the person typed it.
	 * @param string $expression The same query as {@see SearchExpression::build()} makes it.
	 * @return ConditionTree|null Null when this source has no branch to add: the words of the query answer no row.
	 */
	public function conditionOf(string $search, string $expression, int $ownerId): ?ConditionTree
	{
		$content = self::INDEX_REFERENCE . '.SEARCH_CONTENT';

		if (!$this->isOwnerKnownForEveryIndexedRow() || !$this->isTermOfTheOwnerLongEnoughToBeIndexed($ownerId))
		{
			return Query::filter()->whereMatch($content, $expression);
		}

		if (!$this->isTheQueryAloneAnsweredByARow($search, $expression))
		{
			return null;
		}

		$helper = Application::getConnection()->getSqlHelper();

		return Query::filter()
			->where(self::INDEX_REFERENCE . '.USER_ID', $ownerId)
			->whereMatch($content, $this->matchOfTheOwnerAndTheQuery($helper, $search, $ownerId))
		;
	}

	/**
	 * The guard of the shape of the owner, asked once per expression.
	 */
	private function isTheQueryAloneAnsweredByARow(string $search, string $expression): bool
	{
		return $this->queryAloneAnswersARow[$expression] ??= $this->doesTheQueryAloneAnswerARow($search);
	}

	/**
	 * Whether the words of the query, the term of the owner left out, are answered by any row of the search table.
	 *
	 * The parser of the fulltext index keeps a word of its own and drops quietly whatever does not fit it -
	 * letter-like signs on MySQL, the stop words of the english configuration the core pins on PostgreSQL - while
	 * {@see Helper::splitWords()} calls a word whatever Unicode calls a letter. An expression of the owner whose
	 * words were all dropped is the term alone, which every row of that owner carries. Asked of the words alone,
	 * the same expression answers nothing, and that is told by one row of the whole table: there is no owner to
	 * bound it by and none is needed - a row that answers says the words held, a table with none says they did not,
	 * and either answer is the same for every owner.
	 *
	 * What it decides cannot be decided wrongly for the person: no row answers the words means no row of theirs
	 * does either, so the branch it takes away would have answered with the term alone or with nothing.
	 *
	 * A query of its own and not a second MATCH of the query of the page: MySQL initialises every MATCH of a
	 * statement, and a second one would be paid for on every read of every search.
	 *
	 * Protected because the answer of a fulltext pass is the one thing a test case cannot produce: InnoDB does not
	 * refresh a fulltext index before the commit and a case runs inside a transaction.
	 *
	 * @internal Widened for the tests of the guard and for nothing else. This is no point of extension.
	 */
	protected function doesTheQueryAloneAnswerARow(string $search): bool
	{
		$helper = Application::getConnection()->getSqlHelper();

		$row = CallUserLogIndexTable::query()
			->addSelect('USERLOG_ID')
			->whereMatch('SEARCH_CONTENT', $this->matchOfTheQueryAlone($helper, $search))
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $row !== false;
	}

	/**
	 * The fulltext expression of the shape of the owner: the term naming the owner, then every word of the query.
	 *
	 * Composed here and not by {@see SearchExpression::build()}, for two reasons. The term belongs to this source
	 * alone - the other source matches the same query against the user directory of the core, where no row
	 * carries a term of this family. And the shape has to stay flat: the builder of the core wraps its required
	 * terms in brackets ({@see Helper::matchAgainstWildcard()}), and a bracketed group was measured to cost MySQL
	 * two to three times a flat one on a wide token.
	 *
	 * Nothing of the dialect is written out here: both the separator between the terms and the wildcard come from
	 * the helper, so the one composition serves either engine.
	 */
	private function matchOfTheOwnerAndTheQuery(SqlHelper $helper, string $search, int $userId): string
	{
		$terms = array_merge([OwnerTerm::stored($userId)], $this->wordsOfTheQueryAsPrefixes($helper, $search));

		return $helper->getMatchAndExpression($terms, false);
	}

	/**
	 * The same expression with the term of the owner left out of it: what the query asks of the index on its own,
	 * which is what {@see self::doesTheQueryAloneAnswerARow()} asks the whole table. Flat for the same reason, since
	 * it is asked of the same column.
	 */
	private function matchOfTheQueryAlone(SqlHelper $helper, string $search): string
	{
		return $helper->getMatchAndExpression($this->wordsOfTheQueryAsPrefixes($helper, $search), false);
	}

	/**
	 * Every word of the query as the fulltext pass is asked about it: as the beginning of a word the index holds.
	 *
	 * The wildcard is asked of the helper because the $prefixSearch flag of getMatchAndExpression() is one for
	 * every term it is given, and the term of the owner carries none: it is a whole word the write path wrote whole,
	 * and it is asked exactly - that one word of the index, not the words that begin with it. That no term of the
	 * family begins another is kept by the terminator ({@see OwnerTerm::TERMINATOR}), not here. The or-expression of
	 * a single word is that word followed by the sign of the dialect, so dropping the word leaves the sign.
	 *
	 * @return string[]
	 */
	private function wordsOfTheQueryAsPrefixes(SqlHelper $helper, string $search): array
	{
		$wildcard = substr($helper->getMatchOrExpression(['w'], true), 1);

		$words = [];
		foreach ($this->searchExpression->parseProtectedWords($search) as $word)
		{
			$words[] = $word . $wildcard;
		}

		return $words;
	}

	/**
	 * Whether every row of the search table that a search can reach names its owner.
	 *
	 * Asked of the rows and not of the switch of the pass that fills them: the switch is the bookkeeping of the
	 * pass, and an update stopped before it, a table restored from an older copy or a row written by code that
	 * knows of no owner each leave the two disagreeing. One row is enough to tell, and the index of the owner
	 * column answers it at a single point. A row whose journal row is gone is left out by the join: nothing can
	 * fill it and no search can reach it, and counting it would keep the faster shape away for good.
	 *
	 * Asked once and remembered for the lifetime of this source, which is the lifetime of the service that holds
	 * it. An answer kept too long is safe - the rows only ever get filled, so it costs the slower shape and never
	 * the answer.
	 *
	 * Protected so that a test committing rows of its own - which no transaction can roll back - can choose the
	 * shape without leaving a row of the portal changed.
	 *
	 * @internal Widened for the tests of the search and for nothing else. This is no point of extension.
	 * @see \Bitrix\Call\Update\CallUserLogOwnerBackfill
	 */
	protected function isOwnerKnownForEveryIndexedRow(): bool
	{
		if ($this->ownerKnownForEveryIndexedRow === null)
		{
			$query = CallUserLogTable::query();
			$query->registerRuntimeField(self::INDEX_REFERENCE, self::referenceToTheIndex(Join::TYPE_INNER));

			$rowWithoutAnOwner = $query
				->addSelect('ID')
				->whereNull(self::INDEX_REFERENCE . '.USER_ID')
				->setLimit(1)
				->exec()
				->fetch()
			;

			$this->ownerKnownForEveryIndexedRow = $rowWithoutAnOwner === false;
		}

		return $this->ownerKnownForEveryIndexedRow;
	}

	/**
	 * Whether the term naming the owner is long enough for the fulltext index to hold it at all.
	 *
	 * The term is the stored prefix, the decimal id and the stored terminator, so it is three characters and the
	 * digits of the id. A portal that raised the shortest word its index holds above that keeps the term of the
	 * shortest ids out of the index, and a required term the index holds nothing of is not dropped from the
	 * expression by InnoDB - it stays required and the whole query answers with nothing.
	 *
	 * Asked of the connection and of nothing else, the very assumption the write path already stands on:
	 * {@see \Bitrix\Main\Search\MapBuilder::build()} measures its own threshold by the same value.
	 */
	private function isTermOfTheOwnerLongEnoughToBeIndexed(int $userId): bool
	{
		return mb_strlen(OwnerTerm::stored($userId)) >= $this->minTokenSizeOfTheConnection();
	}

	/**
	 * The shortest word the fulltext index of this connection holds.
	 *
	 * Protected because {@see Helper::getMinTokenSize()} remembers the value in a static of its own, so a test
	 * cannot put a threshold of its own in front of it by way of the configuration.
	 *
	 * @internal Widened for the tests of the search and for nothing else. This is no point of extension.
	 */
	protected function minTokenSizeOfTheConnection(): int
	{
		return (int)Helper::getMinTokenSize();
	}
}
