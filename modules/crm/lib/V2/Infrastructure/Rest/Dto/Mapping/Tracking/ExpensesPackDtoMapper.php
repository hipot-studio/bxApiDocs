<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\ExpensesPackDto;
use Bitrix\Crm\V2\Internal\Entity\Tracking\ExpensesPack;

/**
 * Maps a pack of manual expenses of the domain to its REST contract and the input of
 * `crm.tracking.expenses.add` to the pack to add.
 */
final class ExpensesPackDtoMapper
{
	/**
	 * @param string[] $select the top-level fields to answer; empty answers every field
	 */
	public function toDto(ExpensesPack $pack, array $select = []): ExpensesPackDto
	{
		$values = [
			'id' => (int)$pack->id,
			'sourceId' => $pack->sourceId,
			'dateFrom' => clone $pack->dateFrom,
			'dateTo' => clone $pack->dateTo,
			'amount' => $pack->amount,
			'currencyId' => $pack->currencyId,
			'actions' => $pack->actions,
			'comment' => $pack->comment,
			'createdTime' => $pack->createdTime === null ? null : clone $pack->createdTime,
		];
		$fields = empty($select)
			? ExpensesPackDto::OUTPUT_FIELDS
			: array_intersect(ExpensesPackDto::OUTPUT_FIELDS, $select)
		;

		$dto = new ExpensesPackDto();
		foreach ($fields as $field)
		{
			$dto->{$field} = $values[$field];
		}

		return $dto;
	}

	/**
	 * Reads the pack off the input of `add`, already checked against the DTO in the group `add`: the source,
	 * the period, the amount and the currency are there. The values are checked by the domain.
	 */
	public function fromAddDto(ExpensesPackDto $dto): ExpensesPack
	{
		return new ExpensesPack(
			id: null,
			sourceId: $dto->sourceId,
			dateFrom: $dto->dateFrom,
			dateTo: $dto->dateTo,
			amount: $dto->amount,
			currencyId: $dto->currencyId,
			actions: $dto->actions ?? 0,
			comment: $dto->comment ?? null,
			createdTime: null,
		);
	}
}
