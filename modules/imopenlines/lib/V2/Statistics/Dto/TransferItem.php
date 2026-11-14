<?php

namespace Bitrix\ImOpenLines\V2\Statistics\Dto;

use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\ImOpenLines\Chat;
use Bitrix\ImOpenLines\V2\Statistics\Dictionary;
use Bitrix\Main\Type\DateTime;

/**
 * Read-only REST representation of a single operator transfer row for
 * imopenlines.v2.Session.Transfer.list.
 *
 * Built from a `b_imopenlines_operator_transfer` row (OperatorTransferTable). The row's
 * own CONFIG_ID column is the session's line AT THE TIME OF THAT TRANSFER (not the
 * session's current/present-day CONFIG_ID, which may have changed since via later
 * transfers) - it is passed in as $currentConfigId to satisfy
 * Dictionary::getTransferReason()'s "line the session was on when this transfer
 * happened" parameter, letting it tell a same-line requeue from a cross-line transfer.
 */
final class TransferItem implements RestConvertible
{
	public function __construct(
		private readonly array $transfer,
		private readonly int $currentConfigId,
	)
	{
	}

	public static function getRestEntityName(): string
	{
		return 'transfer';
	}

	public function toRestFormat(array $option = []): array
	{
		$transfer = $this->transfer;

		$toOperatorId = $this->nullableInt($transfer['TRANSFER_USER_ID'] ?? null);

		// toConfigId is the destination line only for a cross-line transfer. On a same-line
		// requeue TRANSFER_LINE_ID equals the row's own CONFIG_ID; per the documented
		// contract (see Entities.md) toConfigId must then be null, not the current line.
		$transferLineId = (int)($transfer['TRANSFER_LINE_ID'] ?? 0);
		$toConfigId = ($transferLineId > 0 && $transferLineId !== (int)$transfer['CONFIG_ID'])
			? $transferLineId
			: null;

		return [
			'sessionId' => (int)$transfer['SESSION_ID'],
			'date' => $this->formatDate($transfer['DATE_CREATE'] ?? null),
			'fromOperatorId' => $this->nullableInt($transfer['USER_ID'] ?? null),
			'toOperatorId' => $toOperatorId,
			'fromConfigId' => (int)$transfer['CONFIG_ID'],
			'toConfigId' => $toConfigId,
			'reason' => Dictionary::getTransferReason($transfer, $this->currentConfigId),
			'mode' => (string)($transfer['TRANSFER_MODE'] ?? ''),
			'type' => (string)($transfer['TRANSFER_TYPE'] ?? ''),
			// initiatorId is the operator who initiated the transfer, and per Entities.md is
			// null for an automatic transfer. OperatorTransferTable.USER_ID is the FROM operator
			// regardless of mode, so it only doubles as the initiator on a MANUAL transfer;
			// AUTO/BOT transfers have no human initiator and must serialize as null.
			'initiatorId' => (string)($transfer['TRANSFER_MODE'] ?? '') === Chat::TRANSFER_MODE_MANUAL
				? $this->nullableInt($transfer['USER_ID'] ?? null)
				: null,
		];
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
