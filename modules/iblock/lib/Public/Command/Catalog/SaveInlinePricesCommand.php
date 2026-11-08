<?php

namespace Bitrix\Iblock\Public\Command\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Saves prices and purchasing prices of offers edited inline in the admin element list of an iblock.
 *
 * Values are accepted only for the row element or an active offer of the row product, for rows of the list iblock
 * and the SKU iblock of its catalog. Access rights are checked by the caller. Each element is saved in its own
 * transaction: an error of one element does not roll back the others. Errors carry the element id
 * in the ELEMENT_ID custom data.
 */
final class SaveInlinePricesCommand extends AbstractCommand
{
	public const ERROR_NO_BASE_PRICE = 'NO_BASE_PRICE';
	public const ERROR_SAVE_FAILED = 'SAVE_FAILED';
	public const ERROR_CATALOG_NOT_INSTALLED = 'CATALOG_NOT_INSTALLED';

	/**
	 * @param int $listIblockId Iblock of the list.
	 * @param bool $useOffers Product rows show the selected offer (new product card).
	 * @param PriceInput[] $prices
	 * @param PurchasingPriceInput[] $purchasingPrices
	 */
	public function __construct(
		public readonly int $listIblockId,
		public readonly bool $useOffers,
		public readonly array $prices = [],
		public readonly array $purchasingPrices = [],
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();
		if (!Loader::includeModule('catalog'))
		{
			return $result->addError(new Error('Catalog module is not installed', self::ERROR_CATALOG_NOT_INSTALLED));
		}

		$connection = Application::getConnection();
		foreach ((new SaveInlinePricesCommandHandler())($this) as $elementId => $saveElement)
		{
			$connection->startTransaction();
			try
			{
				$error = $saveElement();
			}
			catch (\Throwable $exception)
			{
				$connection->rollbackTransaction();
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);
				$result->addError(
					new Error($exception->getMessage(), self::ERROR_SAVE_FAILED, ['ELEMENT_ID' => $elementId]),
				);

				continue;
			}
			$connection->commitTransaction();

			if ($error !== null)
			{
				$result->addError($error);
			}
		}

		return $result;
	}
}
