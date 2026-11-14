<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\Service\Accounting;
use Bitrix\Crm\V2\Internal\Entity\Tracking\ExpensesPack;
use Bitrix\Crm\V2\Internal\Exception\Tracking\ExpensesPackNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingValidationException;
use Bitrix\Crm\V2\Internal\Integration\Currency\CurrencyGateway;
use Bitrix\Crm\V2\Internal\Repository\Tracking\ExpensesRepository;
use Bitrix\Crm\V2\Internal\Repository\Tracking\SourceRepository;

/**
 * The manual expenses of the sources of the end-to-end analytics: a pack of a period is added with its days
 * split the way the expenses screen splits them, the packs are read and deleted. The packs of the
 * advertising integration are neither read nor deleted here.
 *
 * A pack the expenses screen would save with wrong values or drop silently is refused here before anything is
 * written; the refused field is named as in the REST fields of a pack. The amount and the actions are refused
 * too when the columns of the packs would not keep them as they are: an amount that is not finite, that rounds
 * to zero or has more digits than the table layer writes, actions that do not fit int.
 *
 * @internal
 */
final class ExpensesService
{
	private const MAX_DAYS = 365;
	private const MAX_COMMENT_LENGTH = 255;
	/** The columns of the amounts are decimal(18,2). */
	private const STORED_AMOUNT_PRECISION = 2;
	/**
	 * The table layer writes a float with the 14 significant digits of the setting `precision`: 12 digits before
	 * the point and 2 after it are kept exactly, in the pack and in each of its days.
	 */
	private const AMOUNT_LIMIT = 1e12;
	/** The columns of the actions are int. */
	private const MAX_ACTIONS = 2_147_483_647;

	public function __construct(
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly SourceRepository $sources = new SourceRepository(),
		private readonly ExpensesRepository $expenses = new ExpensesRepository(),
		private readonly ExpensesSplitter $splitter = new ExpensesSplitter(),
		private readonly CurrencyGateway $currencies = new CurrencyGateway(),
	)
	{
	}

	/**
	 * Adds the pack of an active or archived source together with its days. The id and the time of addition
	 * of the given pack are not used.
	 *
	 * @return int the id of the new pack
	 * @throws TrackingAccessDeniedException
	 * @throws TrackingValidationException
	 */
	public function add(int $userId, ExpensesPack $pack): int
	{
		$this->access->assertCanModify($userId);
		$this->assertValid($pack);

		$newPack = new ExpensesPack(
			id: null,
			sourceId: $pack->sourceId,
			dateFrom: $pack->dateFrom,
			dateTo: $pack->dateTo,
			amount: $pack->amount,
			currencyId: $pack->currencyId,
			actions: $pack->actions,
			comment: $pack->comment,
			createdTime: null,
		);
		$days = $this->splitter->split(
			$pack->dateFrom,
			$pack->dateTo,
			$pack->amount,
			$pack->currencyId,
			$pack->actions,
		);

		return TraceTransaction::run(fn(): int => $this->expenses->create($newPack, $days));
	}

	/**
	 * @return list<ExpensesPack> a page of the manual packs of the source, the latest first; none when there is
	 *     no such source
	 * @throws TrackingAccessDeniedException
	 */
	public function list(int $userId, int $sourceId, int $limit, int $offset): array
	{
		$this->access->assertCanRead($userId);

		if (!$this->sources->exists($sourceId))
		{
			return [];
		}

		return $this->expenses->listManual($sourceId, $limit, $offset);
	}

	/**
	 * Deletes the manual pack together with its days.
	 *
	 * @throws TrackingAccessDeniedException
	 * @throws ExpensesPackNotFoundException there is no manual pack with the id
	 */
	public function delete(int $userId, int $packId): void
	{
		$this->access->assertCanModify($userId);

		if ($this->expenses->getManual($packId) === null)
		{
			throw new ExpensesPackNotFoundException($packId);
		}

		TraceTransaction::run(fn() => $this->expenses->delete($packId));
	}

	/**
	 * @throws TrackingValidationException
	 */
	private function assertValid(ExpensesPack $pack): void
	{
		if (!$this->sources->exists($pack->sourceId))
		{
			throw new TrackingValidationException(
				'sourceId',
				'CRM_V2_TRACKING_EXPENSES_SOURCE_NOT_FOUND',
				['#ID#' => $pack->sourceId],
			);
		}

		if (!is_finite($pack->amount))
		{
			throw new TrackingValidationException('amount', 'CRM_V2_TRACKING_EXPENSES_AMOUNT_NOT_A_NUMBER');
		}

		if ($pack->amount <= 0)
		{
			throw new TrackingValidationException('amount', 'CRM_V2_TRACKING_EXPENSES_AMOUNT_NOT_POSITIVE');
		}

		if (!in_array($pack->currencyId, $this->currencies->getCodes(), true))
		{
			throw new TrackingValidationException('currencyId', 'CRM_V2_TRACKING_EXPENSES_CURRENCY_UNKNOWN');
		}

		$this->assertAmountStored($pack->amount, $pack->currencyId);

		if ($pack->actions < 0)
		{
			throw new TrackingValidationException('actions', 'CRM_V2_TRACKING_EXPENSES_ACTIONS_NEGATIVE');
		}

		if ($pack->actions > self::MAX_ACTIONS)
		{
			throw new TrackingValidationException(
				'actions',
				'CRM_V2_TRACKING_EXPENSES_ACTIONS_TOO_LARGE',
				['#MAX#' => self::MAX_ACTIONS],
			);
		}

		$dayCount = $this->splitter->countDays($pack->dateFrom, $pack->dateTo);
		if ($dayCount <= 0)
		{
			throw new TrackingValidationException('dateFrom', 'CRM_V2_TRACKING_EXPENSES_PERIOD_REVERSED');
		}

		if ($dayCount > self::MAX_DAYS)
		{
			throw new TrackingValidationException(
				'dateTo',
				'CRM_V2_TRACKING_EXPENSES_PERIOD_TOO_LONG',
				['#MAX#' => self::MAX_DAYS],
			);
		}

		if ($pack->comment !== null && mb_strlen($pack->comment) > self::MAX_COMMENT_LENGTH)
		{
			throw new TrackingValidationException(
				'comment',
				'CRM_V2_TRACKING_EXPENSES_COMMENT_TOO_LONG',
				['#MAX#' => self::MAX_COMMENT_LENGTH],
			);
		}
	}

	/**
	 * The amount is kept to the precision of its currency, but never finer than the columns keep it: a positive
	 * amount that rounds to zero would be stored as a pack without expenses.
	 *
	 * The table layer writes a float as PHP renders it, to the digits of the setting `precision`, so the limit is
	 * checked on the rendered amount: an amount just below it may be rendered as the limit itself.
	 *
	 * @throws TrackingValidationException
	 */
	private function assertAmountStored(float $amount, string $currencyId): void
	{
		$precision = min(Accounting::getPricePublicPrecision($currencyId), self::STORED_AMOUNT_PRECISION);
		$rounded = Accounting::round($amount, $precision);

		if ($rounded <= 0)
		{
			throw new TrackingValidationException(
				'amount',
				'CRM_V2_TRACKING_EXPENSES_AMOUNT_TOO_SMALL',
				['#MIN#' => number_format(10 ** -$precision, $precision, '.', '')],
			);
		}

		if ((float)(string)$rounded >= self::AMOUNT_LIMIT)
		{
			throw new TrackingValidationException(
				'amount',
				'CRM_V2_TRACKING_EXPENSES_AMOUNT_TOO_LARGE',
				['#LIMIT#' => number_format(self::AMOUNT_LIMIT, 0, '.', '')],
			);
		}
	}
}
