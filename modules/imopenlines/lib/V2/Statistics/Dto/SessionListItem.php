<?php

namespace Bitrix\ImOpenLines\V2\Statistics\Dto;

use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\ImOpenLines\V2\Statistics\Dictionary;
use Bitrix\Main\Type\DateTime;

/**
 * Read-only REST representation of a single session row for imopenlines.v2.Session.list.
 *
 * Instances are built directly from a `SessionTable::getList()` row (plus the joined
 * `CONFIG.KPI_FIRST_ANSWER_TIME`), so all values are already scalars/DateTime, not ORM objects.
 */
final class SessionListItem implements RestConvertible
{
	public function __construct(
		private readonly array $session,
	)
	{
	}

	public static function getRestEntityName(): string
	{
		return 'session';
	}

	public function toRestFormat(array $option = []): array
	{
		$session = $this->session;

		$voteHead = (int)($session['VOTE_HEAD'] ?? 0);
		$closeReason = null;
		$status = Dictionary::getSessionStatus($session);

		if ($status === Dictionary::SESSION_STATUS_CLOSED || $status === Dictionary::SESSION_STATUS_SPAM)
		{
			// Dictionary::getCloseReason() cannot see who closed the session on its own
			// (see Dictionary.php doc) - derive it here from DATE_OPERATOR_CLOSE, the same
			// proxy SessionListService::applyCloseReasonFilter() uses, so a closeReason=operator
			// filter and this REST field agree on the same rows.
			$session['IS_CLOSED_BY_OPERATOR'] = !empty($session['DATE_OPERATOR_CLOSE']) ? 'Y' : 'N';
			$closeReason = Dictionary::getCloseReason($session);
		}

		$fields = [
			'id' => (int)$session['ID'],
			'configId' => (int)$session['CONFIG_ID'],
			'source' => (string)($session['SOURCE'] ?? ''),
			'operatorId' => $this->nullableInt($session['OPERATOR_ID'] ?? null),
			'userId' => (int)($session['USER_ID'] ?? 0),
			'userCode' => (string)($session['USER_CODE'] ?? ''),
			'chatId' => (int)($session['CHAT_ID'] ?? 0),
			'dateCreate' => $this->formatDate($session['DATE_CREATE'] ?? null),
			'dateClose' => $this->formatDate($session['DATE_CLOSE'] ?? null),
			'dateFirstAnswer' => $this->formatDate($session['DATE_FIRST_ANSWER'] ?? null),
			'dateOperatorAnswer' => $this->formatDate($session['DATE_OPERATOR_ANSWER'] ?? null),
			'status' => $status,
			'closeReason' => $closeReason,
			'vote' => Dictionary::getVote((int)($session['VOTE'] ?? 0)),
			'voteHead' => $voteHead > 0 ? $voteHead : null,
			'commentHead' => ($session['COMMENT_HEAD'] ?? '') !== '' ? (string)$session['COMMENT_HEAD'] : null,
			'crmEntityType' => $session['CRM_ENTITY_TYPE'] ?? null,
			'crmEntityId' => isset($session['CRM_ENTITY_ID']) ? (int)$session['CRM_ENTITY_ID'] : null,
			'queueTransfers' => (int)($session['QUEUE_TRANSFERS'] ?? 0),
			'waitAnswer' => $this->nullableInt($session['TIME_FIRST_ANSWER'] ?? null),
			'waitClose' => $this->nullableInt($session['TIME_CLOSE'] ?? null),
			'kpiFirstAnswer' => $session['KPI_FIRST_ANSWER'] ?? null,
			'messageCount' => (int)($session['MESSAGE_COUNT'] ?? 0),
		];

		return $fields;
	}

	private function nullableInt(mixed $value): ?int
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		$intValue = (int)$value;

		return $intValue > 0 ? $intValue : null;
	}

	private function formatDate(mixed $date): ?string
	{
		if ($date === null || $date === '')
		{
			return null;
		}

		if ($date instanceof DateTime)
		{
			return $date->format('c');
		}

		return (string)$date;
	}
}
