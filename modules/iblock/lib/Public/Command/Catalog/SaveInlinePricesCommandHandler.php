<?php

namespace Bitrix\Iblock\Public\Command\Catalog;

use Bitrix\Iblock\InheritedProperty\ElementValues;
use Bitrix\Iblock\Internal\Integration\Catalog\LegacyPriceWriter;
use Bitrix\Iblock\Internal\Service\Catalog\InlineEditTargetFilter;
use Bitrix\Iblock\PropertyIndex\Manager;
use Bitrix\Main\Error;

/**
 * Prepares the saving of inline edited prices for SaveInlinePricesCommand.
 */
final class SaveInlinePricesCommandHandler
{
	/**
	 * Returns the saving of each element, in the order the command runs them: purchasing prices of offers first,
	 * then prices. A saving returns an error or null; the command runs each one in its own transaction.
	 *
	 * @param SaveInlinePricesCommand $command
	 * @return \Generator<int, \Closure(): ?Error> Element id => saving.
	 */
	public function __invoke(SaveInlinePricesCommand $command): \Generator
	{
		$filter = new InlineEditTargetFilter($command->listIblockId, $command->useOffers);
		$purchasingPrices = $filter->filterPurchasingPrices($command->purchasingPrices);
		$prices = $filter->filterPrices($command->prices);
		$writer = new LegacyPriceWriter();

		foreach ($purchasingPrices as $offerId => $input)
		{
			yield $offerId => static function () use ($writer, $input): ?Error
			{
				$writer->savePurchasingPrice($input->offerId, $input->price, $input->currency);

				return null;
			};
		}

		if (empty($prices['price']) || empty($prices['currency']))
		{
			return;
		}
		foreach (array_keys($prices['price']) as $elementId)
		{
			if (!isset($prices['currency'][$elementId]))
			{
				continue;
			}
			$elementPrices = array_map(static fn(array $values): array => $values[$elementId] ?? [], $prices);

			yield $elementId => static function () use ($command, $writer, $elementId, $elementPrices): ?Error
			{
				if ($writer->isBasePriceMissing($elementId, $elementPrices))
				{
					return new Error(
						'Base price is not specified',
						SaveInlinePricesCommand::ERROR_NO_BASE_PRICE,
						['ELEMENT_ID' => $elementId],
					);
				}

				$writer->saveElementPrices($elementId, $elementPrices);

				// the iblock of the list is used for offers too, as the admin list scripts did
				(new ElementValues($command->listIblockId, $elementId))->clearValues();
				Manager::updateElementIndex($command->listIblockId, $elementId);

				return null;
			};
		}
	}
}
