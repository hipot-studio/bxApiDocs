<?php

namespace Bitrix\Catalog\InventoryManagement\Helpers;

use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\Model\Product;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\HtmlFilter;

/**
 * Doctor for inventory management.
 *
 * @internal use it only for debug!
 *
 * This object does not contain a "Cure all" button, because inventory management is falling apart for various reasons,
 * and if a large number of products have discrepancies in the catalog,
 * then there are serious problems and you need to understand in more detail what is the matter.
 *
 * Example show summary info:
 * ```php
	\Bitrix\Main\Loader::includeModule('catalog');

	$doctor = new \Bitrix\Catalog\InventoryManagement\Helpers\Doctor();
	$doctor->printInfo();
	// or show only problems
	$doctor->printProblems();
 * ```
 *
 * For fixes see that methods:
 * - fixQuantitiesFromStores
 * - fixReservesFromStores
 * - fixReservesLessZero
 */
final class Doctor
{
	private static bool $isDoctorWorking = false;

	public static function isDoctorWorking(): bool
	{
		return self::$isDoctorWorking;
	}

	/**
	 * Get SQL to select information about reserves and stocks.
	 *
	 * It can be used for customization and direct SQL queries.
	 *
	 * @return string
	 */
	private function getSql(): string
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();
		return '
			SELECT
				cp.ID AS ' . $helper->quote('PRODUCT_ID') . ',
				cp.QUANTITY AS ' . $helper->quote('PRODUCT_QUANTITY_AVAILABLE') . ',
				csp.QUANTITY_AVAILABLE AS ' . $helper->quote('STORE_QUANTITY_AVAILABLE') . ',
				cp.QUANTITY_RESERVED AS ' . $helper->quote('PRODUCT_QUANTITY_RESERVED') . ',
				csp.QUANTITY_RESERVED AS ' . $helper->quote('STORE_QUANTITY_RESERVED') . ',
				sbr.QUANTITY_RESERVED AS ' . $helper->quote('SALE_QUANTITY_RESERVED') . '
			FROM
				b_catalog_product AS cp
				LEFT JOIN (
					SELECT
						PRODUCT_ID,
						SUM(AMOUNT) as QUANTITY,
						SUM(QUANTITY_RESERVED) as QUANTITY_RESERVED,
						SUM(AMOUNT - QUANTITY_RESERVED) as QUANTITY_AVAILABLE
					FROM
						b_catalog_store_product
						INNER JOIN b_catalog_store ON b_catalog_store.ID = b_catalog_store_product.STORE_ID AND b_catalog_store.ACTIVE = \'Y\'
					GROUP BY PRODUCT_ID
				) as csp ON cp.ID = csp.PRODUCT_ID
				LEFT JOIN (
					SELECT
						sb.PRODUCT_ID,
						SUM(sbr.QUANTITY) AS QUANTITY_RESERVED
					FROM
						b_sale_basket AS sb
						LEFT JOIN b_sale_basket_reservation AS sbr ON sbr.BASKET_ID = sb.ID AND sbr.QUANTITY != 0
					WHERE
						sb.PRODUCT_ID > 0
						AND sbr.QUANTITY != 0
					GROUP BY sb.PRODUCT_ID
				) as sbr ON cp.ID = sbr.PRODUCT_ID
			WHERE
				(
					cp.QUANTITY != 0
					OR cp.QUANTITY_RESERVED != 0
					OR csp.QUANTITY != 0
					OR csp.QUANTITY_RESERVED != 0
					OR csp.QUANTITY_AVAILABLE != 0
					OR sbr.QUANTITY_RESERVED != 0
				)
		';
	}

	/**
	 * Print catalog and sale problems.
	 *
	 * @return void
	 */
	public function printProblems(): void
	{
		$sql = $this->getSql() . ' AND (
			cp.QUANTITY != csp.QUANTITY_AVAILABLE OR csp.QUANTITY_AVAILABLE IS NULL
			OR cp.QUANTITY_RESERVED != csp.QUANTITY_RESERVED OR csp.QUANTITY_RESERVED IS NULL
			OR sbr.QUANTITY_RESERVED > cp.QUANTITY_RESERVED
			OR cp.QUANTITY_RESERVED < 0
			OR csp.QUANTITY_RESERVED < 0
		)';

		$result = [];

		$rows = Application::getConnection()->query($sql);
		foreach ($rows as $row)
		{
			$problems = [];

			$storeReserveQuantity = (float)$row['STORE_QUANTITY_RESERVED'];
			$productReserveQuantity = (float)$row['PRODUCT_QUANTITY_RESERVED'];

			if ((float)$row['PRODUCT_QUANTITY_AVAILABLE'] !== (float)$row['STORE_QUANTITY_AVAILABLE'])
			{
				$problems[] = 'Available quantity not match';
			}

			if ($productReserveQuantity !== $storeReserveQuantity)
			{
				$problems[] = 'Reserve quantity not match';
			}

			if ($productReserveQuantity < 0.0)
			{
				$problems[] = 'Product reserve quantity less than 0';
			}

			if ($storeReserveQuantity < 0.0)
			{
				$problems[] = 'Store reserve quantity less than 0';
			}

			if ((float)$row['SALE_QUANTITY_RESERVED'] > $productReserveQuantity)
			{
				$problems[] = 'More is reserved in \'sale\' than in \'catalog\'';
			}

			if (empty($problems))
			{
				$problems[] = 'Unknown, check SQL';
			}

			$result[] = ['PROBLEMS' => join('; ', $problems)] + $row;
		}

		$this->printTable($result);
	}

	/**
	 * Print info about catalog and sale actual quantities.
	 *
	 * @return void
	 */
	public function printInfo(): void
	{
		$this->printTable(
			Application::getConnection()->query($this->getSql())->fetchAll()
		);
	}

	/**
	 * @param array[] $rows
	 *
	 * @return void
	 */
	private function printTable(array $rows): void
	{
		if (empty($rows))
		{
			echo '-- empty --';
			return;
		}

		$headers = array_keys(
			current($rows)
		);

		echo '<table border="1" cellspacing="0" cellpadding="2"><tr>';
		foreach ($headers as $header)
		{
			echo '<th>' . HtmlFilter::encode($header) . '</th>';
		}

		foreach ($rows as $row)
		{
			echo '<tr>';

			foreach ($headers as $header)
			{
				$value = isset($row[$header]) && $row[$header] !== '' ? $row[$header] : '-';
				echo '<td>' . HtmlFilter::encode($value) . '</td>';
			}

			echo '</td>';
		}

		echo '</table>';
	}

	/**
	 * Sets value to `b_catalog_product.QUANTITY` from stores amount.
	 *
	 * @param int ...$productIds
	 *
	 * @return void
	 */
	public function fixQuantitiesFromStores(int ...$productIds): void
	{
		if (empty($productIds))
		{
			throw new ArgumentNullException('productIds');
		}

		$result = [];

		$productIdsSql = join(',', $productIds);
		$sql = "
			SELECT
				PRODUCT_ID,
				SUM(AMOUNT) as QUANTITY
			FROM
				b_catalog_store_product
				INNER JOIN b_catalog_store ON b_catalog_store.ID = b_catalog_store_product.STORE_ID AND b_catalog_store.ACTIVE = 'Y'
			WHERE
				PRODUCT_ID IN ({$productIdsSql})
			GROUP BY
				PRODUCT_ID
		";
		$rows = Application::getConnection()->query($sql);
		foreach ($rows as $row)
		{
			$productId = (int)$row['PRODUCT_ID'];

			$result[$productId] = [
				'PRODUCT_ID' => $productId,
				'NEW_QUANTITY' => (float)$row['QUANTITY'],
			];
		}

		// fill products without store quantities
		foreach ($productIds as $productId)
		{
			$result[$productId] ??= [
				'PRODUCT_ID' => $productId,
				'NEW_QUANTITY' => 0.0,
			];
		}

		// update products
		foreach ($result as $productId => &$item)
		{
			// or \Bitrix\Catalog\Model\Product::update ?
			$saveResult = ProductTable::update($productId, [
				'QUANTITY' => $item['NEW_QUANTITY'],
			]);
			$item['SAVE_RESULT'] =
				$saveResult->isSuccess()
					? 'ok'
					: join(', ', $saveResult->getErrorMessages())
			;
		}

		$this->printTable($result);
	}

	/**
	 * Sets value to `b_catalog_product.QUANTITY_RESERVE` from stores amount.
	 *
	 * @param int ...$productIds
	 *
	 * @return void
	 */
	public function fixReservesFromStores(int ...$productIds): void
	{
		if (empty($productIds))
		{
			throw new ArgumentNullException('productIds');
		}

		$result = [];

		$productIdsSql = join(',', $productIds);
		$sql = "
			SELECT
				PRODUCT_ID,
				SUM(QUANTITY_RESERVED) as QUANTITY_RESERVED
			FROM
				b_catalog_store_product
				INNER JOIN b_catalog_store ON b_catalog_store.ID = b_catalog_store_product.STORE_ID AND b_catalog_store.ACTIVE = 'Y'
			WHERE
				PRODUCT_ID IN ({$productIdsSql})
			GROUP BY
				PRODUCT_ID
		";
		$rows = Application::getConnection()->query($sql);
		foreach ($rows as $row)
		{
			$productId = (int)$row['PRODUCT_ID'];

			$result[$productId] = [
				'PRODUCT_ID' => $productId,
				'NEW_QUANTITY_RESERVED' => (float)$row['QUANTITY_RESERVED'],
			];
		}

		// fill products without store quantities
		foreach ($productIds as $productId)
		{
			$result[$productId] ??= [
				'PRODUCT_ID' => $productId,
				'NEW_QUANTITY_RESERVED' => 0.0,
			];
		}

		// update products
		foreach ($result as $productId => &$item)
		{
			// or \Bitrix\Catalog\Model\Product::update ?
			$saveResult = ProductTable::update($productId, [
				'QUANTITY_RESERVED' => $item['NEW_QUANTITY_RESERVED'],
			]);
			$item['SAVE_RESULT'] =
				$saveResult->isSuccess()
					? 'ok'
					: join(', ', $saveResult->getErrorMessages())
			;
		}

		$this->printTable($result);
	}

	/**
	 * Sets values to `b_catalog_product.QUANTITY_RESERVE` and `b_catalog_store_product.QUANTITY_RESERVE` as `0`
	 * if quantity less than `0`.
	 *
	 * @return void
	 */
	public function fixReservesLessZero(): void
	{
		$db = Application::getConnection();

		// products
		$db->queryExecute(
			'UPDATE b_catalog_product SET QUANTITY_RESERVED = 0 WHERE QUANTITY_RESERVED < 0'
		);
		if ($db->getAffectedRowsCount() > 0)
		{
			ProductTable::cleanCache();
		}

		// stores
		$db->queryExecute(
			'UPDATE b_catalog_store_product SET QUANTITY_RESERVED = 0 WHERE QUANTITY_RESERVED < 0'
		);
		if ($db->getAffectedRowsCount() > 0)
		{
			StoreProductTable::cleanCache();
		}
	}

	public function printProductWithFailStoreAmount(): void
	{
		$conn = Application::getConnection();
		$helper = $conn->getSqlHelper();

		$sql = '
			SELECT
				sb.' . $helper->quote('ELEMENT_ID') . ' AS ' . $helper->quote('PRODUCT_ID') . ',
				sb.' . $helper->quote('STORE_ID') . ',
				sb.' . $helper->quote('BATCH_AMOUNT') . ',
				csp.ID AS ' . $helper->quote('STORE_AMOUNT_ID') . ',
				COALESCE(csp.AMOUNT, 0) AS ' . $helper->quote('STORE_AMOUNT') . ',
				ABS(sb.BATCH_AMOUNT - COALESCE(csp.AMOUNT, 0)) AS ' . $helper->quote('DIFF') . '
			FROM (
				SELECT
					ELEMENT_ID,
					STORE_ID,
					SUM(AVAILABLE_AMOUNT) AS BATCH_AMOUNT
				FROM
					b_catalog_store_batch
				GROUP BY
					ELEMENT_ID, STORE_ID
			) AS sb
			LEFT JOIN b_catalog_store_product AS csp
				ON csp.PRODUCT_ID = sb.ELEMENT_ID AND csp.STORE_ID = sb.STORE_ID
			WHERE
				sb.BATCH_AMOUNT != COALESCE(csp.AMOUNT, 0)
			ORDER BY
				sb.ELEMENT_ID, sb.STORE_ID
		';

		$this->printTable($conn->query($sql)->fetchAll());
	}

	public function fixProductQuantitySubtract(int $productId, float $diff): void
	{
		if ($productId <= 0)
		{
			throw new ArgumentNullException('productId');
		}

		if ($diff <= 0.0)
		{
			throw new \InvalidArgumentException('Amount must be positive.');
		}

		$row = ProductTable::getList([
			'select' => ['ID', 'QUANTITY'],
			'filter' => ['=ID' => $productId],
		])->fetch();

		if (!$row)
		{
			throw new SystemException('Product not found.');
		}

		$currentQuantity = (float)$row['QUANTITY'];
		$newQuantity = $currentQuantity - $diff;

		$saveResult = Product::update($productId, [
			'QUANTITY' => $newQuantity,
		]);

		if (!$saveResult->isSuccess())
		{
			throw new SystemException($saveResult->getError()->getMessage());
		}
	}

	public function cancelFailedDocument(int $documentId): void
	{
		self::$isDoctorWorking = true;
		\CCatalogDocs::cancellationDocument($documentId);
		self::$isDoctorWorking = false;
	}
}
