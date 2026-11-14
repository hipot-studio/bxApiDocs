<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RelationIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;
use Bitrix\Crm\V2\Internal\Entity\Summary\TruncationReason;
use Bitrix\Main\Type\Date;
use CCrmOwnerType;

/**
 * What the deal was billed by: the smart invoices hanging off it, each one by its number, its amount
 * in the currency of its own, the stage it stands at and the date it is dated by.
 *
 * The invoices are found through the one relation of the deal to the smart invoices, asked for by
 * itself: a relation answers the children of its own child type alone, so what comes back is the
 * invoices and nothing else. The manager of the relations would answer the children of every type a
 * deal may have at once - quotes, orders, smart processes an administrator tied to deals - and every
 * one of those costs a query the section has no use for. Old invoices and payments are no part of the
 * section by the decision of the feature: the one told about is the entity type of the module today.
 *
 * A relation checks no rights whatsoever, so the right is applied by the read itself - by the
 * selection that answers the invoices, the way {@see DealSelector::selectAccessible()} reads the
 * deals of a client. It is applied for the user the summary is collected for, who is not the one the
 * process runs as.
 *
 * Two refusals of the rights are told apart. Every invoice out of reach leaves the section restricted:
 * "the deal was never billed" and "the invoices are not this user's to see" are different facts. Some
 * of them out of reach leaves the section filled with the rest and admitting a part of it is missing,
 * so that a shortened list is not read as a whole one.
 *
 * A portal with the smart invoices switched off has no such entity type at all, and the section is
 * then honestly empty rather than refused: there is no right that would have opened it.
 *
 * @internal
 */
class DealInvoicesReader
{
	/**
	 * What an invoice is told by. The identifier is read for the filter and the order and stays out of
	 * the record: the section tells about invoices of a deal and not about rows to be addressed later.
	 */
	private const INVOICE_SELECT = [
		Item::FIELD_NAME_ID,
		Item\SmartInvoice::FIELD_NAME_ACCOUNT_NUMBER,
		Item::FIELD_NAME_OPPORTUNITY,
		Item::FIELD_NAME_CURRENCY_ID,
		Item::FIELD_NAME_STAGE_ID,
		Item::FIELD_NAME_BEGIN_DATE,
	];

	/**
	 * @param array $dealRow The row of {@see SummaryLimits::DEAL_SUMMARY_EXTRA_SELECT}.
	 */
	public function read(array $dealRow, int $userId): SummarySection
	{
		if (!$this->isSmartInvoiceEnabled())
		{
			return SummarySection::empty();
		}

		$invoiceIds = self::invoiceIdsOf($this->childElements((int)($dealRow[Item::FIELD_NAME_ID] ?? 0)));
		if ($invoiceIds === [])
		{
			return SummarySection::empty();
		}

		$accessibleCount = $this->countAccessible($invoiceIds, $userId);
		$invoices = $accessibleCount > 0
			? $this->readAccessible($invoiceIds, $userId, SummaryLimits::DEAL_INVOICES)
			: []
		;
		if ($invoices === [])
		{
			return SummarySection::restricted();
		}

		return SummarySection::filled(
			array_map($this->recordOf(...), $invoices),
			self::truncationReason(
				$accessibleCount < count($invoiceIds),
				$accessibleCount > count($invoices),
			),
		);
	}

	/**
	 * Whether the portal has the smart invoices at all. The setting answers by the entity type being
	 * installed, which is the very thing the read below needs.
	 */
	protected function isSmartInvoiceEnabled(): bool
	{
		return InvoiceSettings::getCurrent()->isSmartInvoiceEnabled();
	}

	/**
	 * The smart invoices tied under the deal, asked of that one relation alone.
	 *
	 * A portal where the deals are tied to no invoices at all has no such relation, and a deal of it
	 * answers nothing here - the very answer a deal that was never billed gives.
	 *
	 * @return ItemIdentifier[]
	 */
	protected function childElements(int $dealId): array
	{
		return Container::getInstance()->getRelationManager()
			->getRelation(new RelationIdentifier(CCrmOwnerType::Deal, CCrmOwnerType::SmartInvoice))
			?->getChildElements(new ItemIdentifier(CCrmOwnerType::Deal, $dealId))
			?? []
		;
	}

	/**
	 * The children of the relation by their identifiers, in the order the relation answered them.
	 *
	 * Nothing is sorted out and nothing is made unique: the relation is typed, so every child of it is
	 * a smart invoice, and it answers a child once - a binding is kept by the pair of the two, so the
	 * same invoice cannot hang under the same deal twice. The count of the identifiers is what the read
	 * below is judged against, and a doubled one would turn a whole section into a partially restricted
	 * one.
	 *
	 * @param ItemIdentifier[] $children
	 * @return int[]
	 */
	private static function invoiceIdsOf(array $children): array
	{
		return array_map(static fn (ItemIdentifier $child): int => $child->getEntityId(), $children);
	}

	/**
	 * How many of the invoices of the deal this user may see.
	 *
	 * It is counted by a query of its own and not taken from the rows that came back, because the read
	 * below is bound to the limit of the section: a read cut to ten answers the same ten for a deal
	 * whose older invoices are closed to this user and for one where they are merely old. This number
	 * tells the two apart, and it tells the tail cut by the limit as well, at the price of a query that
	 * answers a single number instead of a read of a whole history of invoices.
	 *
	 * @param int[] $invoiceIds
	 */
	protected function countAccessible(array $invoiceIds, int $userId): int
	{
		// unreachable past the gate above: that setting answers by this very type being installed
		$factory = Container::getInstance()->getFactory(CCrmOwnerType::SmartInvoice);
		if ($factory === null)
		{
			return 0;
		}

		return $factory->getItemsCountFilteredByPermissions(
			['@' . Item::FIELD_NAME_ID => $invoiceIds],
			$userId,
			UserPermissions::OPERATION_READ,
		);
	}

	/**
	 * The invoices as this user may see them, the freshest first and no more of them than asked for.
	 *
	 * The limit is asked of the query itself, so a deal billed in hundreds of parts costs the same read
	 * as one billed in two. What a short answer would have meant - a part closed by the rights or a tail
	 * cut by the section - is told by {@see self::countAccessible()} and never by the count of these rows.
	 *
	 * The date of an invoice is what orders them - it is the date the module itself calls the date of
	 * the invoice - and the identifier settles the invoices of one day between themselves.
	 *
	 * @param int[] $invoiceIds
	 * @return Item[]
	 */
	protected function readAccessible(array $invoiceIds, int $userId, int $limit): array
	{
		// unreachable past the gate above: that setting answers by this very type being installed
		$factory = Container::getInstance()->getFactory(CCrmOwnerType::SmartInvoice);
		if ($factory === null)
		{
			return [];
		}

		return $factory->getItemsFilteredByPermissions(
			[
				'select' => self::INVOICE_SELECT,
				'filter' => ['@' . Item::FIELD_NAME_ID => $invoiceIds],
				'order' => [
					Item::FIELD_NAME_BEGIN_DATE => 'DESC',
					Item::FIELD_NAME_ID => 'DESC',
				],
				'limit' => $limit,
			],
			$userId,
			UserPermissions::OPERATION_READ,
		);
	}

	/**
	 * A part of the section closed by the rights outranks a tail left out by the limit: a limit drops
	 * the oldest of a list of alike records, while the rights take out exactly what this user will not
	 * see anywhere.
	 *
	 * The rights are judged by the count answering fewer invoices than the relation named, here and in
	 * the refusal of the whole section above. An invoice deleted between the read of the relation and
	 * the count answers short in the very same way, and it is deliberately counted as one hidden by the
	 * rights: telling the two apart would cost another read of the storage for a race of two queries of
	 * one call, and a section that says "not yours to see" about an invoice that is gone is the harmless
	 * half of that trade.
	 */
	private static function truncationReason(
		bool $isPartRestricted,
		bool $isItemLimitHit,
	): ?TruncationReason
	{
		return match (true)
		{
			$isPartRestricted => TruncationReason::RestrictedPart,
			$isItemLimitHit => TruncationReason::ItemLimit,
			default => null,
		};
	}

	/**
	 * The currency is named per invoice and not once for the section: an invoice of a deal carries a
	 * currency of its own and needs not be billed in the one the deal is counted in.
	 */
	private function recordOf(Item $invoice): array
	{
		$date = $invoice->get(Item::FIELD_NAME_BEGIN_DATE);
		$stageId = (string)$invoice->get(Item::FIELD_NAME_STAGE_ID);

		return [
			'accountNumber' => (string)$invoice->get(Item\SmartInvoice::FIELD_NAME_ACCOUNT_NUMBER),
			'opportunity' => (float)$invoice->get(Item::FIELD_NAME_OPPORTUNITY),
			'currencyId' => (string)$invoice->get(Item::FIELD_NAME_CURRENCY_ID),
			'stageId' => $stageId,
			'stageSemanticId' => $this->stageSemanticsOf($stageId),
			'date' => $date instanceof Date ? $date->toString() : null,
		];
	}

	/**
	 * What the stage of an invoice means - whether the invoice is still in the making, paid or dropped.
	 *
	 * It is asked of the type and not of the invoice: an entity of a dynamic type keeps the stage it
	 * stands at and no column of what that stage means, and a query asked for such a column answers
	 * with an exception rather than with a row.
	 */
	protected function stageSemanticsOf(string $stageId): string
	{
		$factory = Container::getInstance()->getFactory(CCrmOwnerType::SmartInvoice);

		return (string)$factory?->getStageSemantics($stageId);
	}
}
