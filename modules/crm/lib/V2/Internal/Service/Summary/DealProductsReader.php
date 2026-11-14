<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Item;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;
use Bitrix\Crm\V2\Internal\Entity\Summary\TruncationReason;
use Bitrix\Crm\V2\Internal\Integration\Catalog\CatalogPermissionState;
use Bitrix\Crm\V2\Internal\Integration\Catalog\CatalogPermissions;
use Bitrix\Crm\V2\Internal\Repository\ProductRow\ProductRowRepository;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowFilter;
use Bitrix\Main\Provider\Params\Pager;

/**
 * What a deal is being sold: the rows of it by name, how many and for how much, and the currency they
 * are counted in.
 *
 * The rows are no CRM items and have no rights of their own - neither a factory nor an operation of
 * an item answers about them - so the whole gate of the section is the right to read the catalog. The
 * repository behind the read is read-only and checks nothing by construction, which is why the right
 * is asked before it and not after.
 *
 * The right is asked about the user the summary is collected for. It is never
 * {@see \Bitrix\Catalog\Access\AccessController::getCurrent()}: that one answers about whoever the
 * process runs as, and the summary is assembled on behalf of somebody else.
 *
 * Without the right the section is told restricted and not empty: "there are no products" and "the
 * products are not this user's to see" are different facts, and a consumer that merges them turns a
 * missing right into an honest absence.
 *
 * @internal
 */
class DealProductsReader
{
	private readonly CatalogPermissions $catalogPermissions;

	public function __construct(?CatalogPermissions $catalogPermissions = null)
	{
		$this->catalogPermissions = $catalogPermissions ?? new CatalogPermissions();
	}

	/**
	 * The section and what the journal has to be told about the right behind it.
	 *
	 * The order of the questions is the order the access controller of the catalog branches in: a
	 * portal installing the rights of the catalog is answered before the external catalog, and the
	 * external catalog before the rule of the action.
	 *
	 * @param array $dealRow The row of {@see SummaryLimits::DEAL_SUMMARY_EXTRA_SELECT}.
	 */
	public function read(array $dealRow, int $userId): DealProductsSection
	{
		if (!$this->catalogPermissions->isModuleAvailable())
		{
			return new DealProductsSection(SummarySection::restricted(), false);
		}

		$state = $this->catalogPermissions->permissionState();
		if ($state === CatalogPermissionState::CatalogRightsMigration)
		{
			// A refusal is the honest answer: while the rights are being installed the catalog measures
			// whoever the process runs as, and a silent check of another user's rights is worse.
			return new DealProductsSection(SummarySection::restricted(), false);
		}

		$isRightNotChecked =
			$state === CatalogPermissionState::ExternalCatalog
			|| $state === CatalogPermissionState::AccessChecksDisabled
		;

		if (!$this->catalogPermissions->canRead($userId))
		{
			return new DealProductsSection(SummarySection::restricted(), $isRightNotChecked);
		}

		$rows = $this->readRows((int)($dealRow[Item::FIELD_NAME_ID] ?? 0));
		if ($rows === [])
		{
			return new DealProductsSection(SummarySection::empty(), $isRightNotChecked);
		}

		$kept = array_slice($rows, 0, SummaryLimits::DEAL_PRODUCT_ROWS);

		return new DealProductsSection(
			SummarySection::filled(
				self::sectionOf($kept, (string)($dealRow[Item::FIELD_NAME_CURRENCY_ID] ?? '')),
				count($rows) > count($kept) ? TruncationReason::ItemLimit : null,
			),
			$isRightNotChecked,
		);
	}

	/**
	 * The rows of the deal, in the order the storage keeps them in, read by the one query the owner
	 * index covers.
	 *
	 * One row past the limit of the section is read along with the rest, and no more than that: the
	 * section tells no full list of the rows, and knowing that a row beyond the limit exists is the
	 * whole of what it needs to admit being cut.
	 *
	 * @return ProductRow[]
	 */
	protected function readRows(int $dealId): array
	{
		return (new ProductRowRepository())
			->findAllByOwner(
				EntityType::deal(),
				new ProductRowFilter(ownerId: $dealId),
				null,
				new Pager(SummaryLimits::DEAL_PRODUCT_ROWS + 1),
			)
			->getAll()
		;
	}

	/**
	 * The currency is named once for the whole section because that is where it lives: a deal is kept
	 * in one currency and a row of it carries no currency of its own.
	 *
	 * @param ProductRow[] $rows
	 */
	private static function sectionOf(array $rows, string $currencyId): array
	{
		return [
			'currencyId' => $currencyId,
			'rows' => array_map(self::recordOf(...), $rows),
		];
	}

	/**
	 * The amount of a row is the quantity times the price the row is kept at.
	 *
	 * That price is the one the module stores as `PRICE` of a product row, and
	 * {@see \Bitrix\Crm\ProductRowTable} says what it is: the base price with the discount already
	 * taken off it and the tax already in it. So the amount is what the row costs now, and a number
	 * named after the discount would have to be counted off `PRICE_NETTO`, which no row of this section
	 * carries.
	 *
	 * The sum of the rows is still not the opportunity of the deal the details tell, and the section
	 * promises no such thing: a deal is given its own amount and nothing recounts it from the rows.
	 */
	private static function recordOf(ProductRow $row): array
	{
		return [
			'name' => $row->getName(),
			'quantity' => $row->getQuantity(),
			'price' => $row->getPrice(),
			'amount' => $row->getQuantity() * $row->getPrice(),
		];
	}
}
