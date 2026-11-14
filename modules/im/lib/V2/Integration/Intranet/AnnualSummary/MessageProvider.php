<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Date;

/**
 * Messages sent by the user within the period, notifications excluded.
 *
 * Chats the user has left are counted too: the query does not join the relation table.
 */
class MessageProvider extends AbstractSummaryProvider
{
	public const PROVIDER_ID = 'im.sent_messages';

	/**
	 * Derived from a step budget of 1 s of database time on the benchmark profile of about 12 400
	 * messages per author. A heavier archive costs more per person, and the batch has to shrink.
	 */
	public const USER_ID_LIMIT = 50;

	public function __construct(
		Date $from,
		Date $to,
		private readonly MessageIdRangeResolver $idRangeResolver,
	)
	{
		parent::__construct($from, $to);
	}

	public function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_ID_LIMIT;
	}

	protected function calculate(int $userId): int
	{
		// Resolved on the first counted user, not in the constructor: the channel metric needs no bounds.
		$idRange = $this->idRangeResolver->resolve($this->from, $this->to);

		if ($idRange->idFrom === null)
		{
			return 0;
		}

		// The period is filtered by ID, not by DATE_CREATE: no index of the table carries author
		// and date together, while the identifier rides along in every secondary index.
		$query = MessageTable::query()
			->setSelect(['COUNT'])
			->registerRuntimeField('COUNT', new ExpressionField('COUNT', 'COUNT(*)'))
			->where('AUTHOR_ID', $userId)
			->where('NOTIFY_TYPE', \IM_NOTIFY_MESSAGE)
			->where('ID', '>=', $idRange->idFrom)
		;

		if ($idRange->idTo !== null)
		{
			$query->where('ID', '<', $idRange->idTo);
		}

		$row = $query->fetch();

		return (int)($row['COUNT'] ?? 0);
	}
}
