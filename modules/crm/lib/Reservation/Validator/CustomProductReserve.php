<?php

declare(strict_types=1);

namespace Bitrix\Crm\Reservation\Validator;

use Bitrix\Crm\ProductRow;
use Bitrix\Crm\ProductRowCollection;
use Bitrix\Crm\Reservation\Error\BaseProductErrorAssembler;
use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class CustomProductReserve extends BaseValidator
{
	public const ERROR_CODE = 'ERROR_CUSTOM_PRODUCT_RESERVE';

	protected const NAME_TEMPLATE = '#NAME#';

	protected function initErrorAssembler(): void
	{
		parent::initErrorAssembler();

		$assembler = $this->getErrorAssembler();
		$assembler->setErrorCode(static::ERROR_CODE);
		$assembler->setErrorTemplates([
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_ONE => Loc::getMessage('CRM_RESERVATION_ERROR_CUSTOM_PRODUCT_RESERVE_ERROR_ONE_PRODUCT'),
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_MULTI => Loc::getMessage('CRM_RESERVATION_ERROR_CUSTOM_PRODUCT_RESERVE_ERROR_MULTI_PRODUCTS'),
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_TOO_MANY => Loc::getMessage('CRM_RESERVATION_ERROR_CUSTOM_PRODUCT_RESERVE_ERROR_TOO_MANY_PRODUCTS'),
		]);
		unset(
			$assembler,
		);
	}

	public function validateCollection(?ProductRowCollection $collection): Result
	{
		$result = new Result();

		if ($collection === null)
		{
			return $result;
		}

		$productNames = [];

		/** @var ProductRow $productRow */
		foreach ($collection as $productRow)
		{
			if ($this->isNeedValidateProductRow($productRow))
			{
				$productNames[] = $productRow->getProductName();
			}
		}
		unset(
			$productReservation,
			$productRow,
		);

		return $this->getValidationResult($productNames);
	}

	public function validateRows(array $currentRows, array $actualRows): Result
	{
		if (empty($currentRows))
		{
			return new Result();
		}

		$productNames = [];

		foreach (array_keys($currentRows) as $rowId)
		{
			$row = $currentRows[$rowId];
			if ($this->isNeedValidateProduct($row, $actualRows[$rowId] ?? null))
			{
				$productNames[] = $row['PRODUCT_NAME'];
			}
		}

		return $this->getValidationResult($productNames);
	}

	protected function isNeedValidateProductRow(ProductRow $productRow): bool
	{
		if ((int)$productRow->getProductId() > 0)
		{
			return false;
		}

		/**
		 * @var ProductRowReservation $productReservation
		 */
		$productReservation = $productRow->getProductRowReservation();
		if (!$productReservation)
		{
			return false;
		}

		$reserveQuantity = $productReservation->getReserveQuantity();

		return !empty($reserveQuantity);
	}

	protected function isNeedValidateProduct(array $currentRow, ?array $actualRow): bool
	{
		if (!array_key_exists('PRODUCT_ID', $currentRow))
		{
			return false;
		}
		if ((int)$currentRow['PRODUCT_ID'] > 0)
		{
			return false;
		}
		if (
			!array_key_exists('INPUT_RESERVE_QUANTITY', $currentRow)
			&& !array_key_exists('RESERVE_QUANTITY', $currentRow)
		)
		{
			return false;
		}
		$reserve = (float)($currentRow['INPUT_RESERVE_QUANTITY'] ?? $currentRow['RESERVE_QUANTITY']);
		if ($reserve <= 0)
		{
			return false;
		}

		return true;
	}

	private function getValidationResult(array $productNames): Result
	{
		$result = new Result();

		if (empty($productNames))
		{
			return $result;
		}

		$assembler = $this->getErrorAssembler();
		$assembler->setProductNames($productNames);
		$assembler->setNameTemplate(static::NAME_TEMPLATE);
		$result->addError($assembler->getError());
		unset(
			$assembler,
		);

		return $result;
	}
}
