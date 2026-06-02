<?php

namespace PHPSTORM_META
{
	registerArgumentsSet('bitrix_sale_serviceLocator_codes',
		'sale.basketReservationHistory',
		'sale.basketReservation',
		'sale.reservation.settings',
		'sale.paysystem.manager',
		'sale.entityLabel',
		'sale.basketItemCalculator',
		'sale.basketCalculator',
		'sale.orderCalculator',
		'sale.discountCalculator',
		'sale.vatCalculator',
		'sale.basketItemInputFactory',
		'sale.priceRounder',
	);

	expectedArguments(\Bitrix\Main\DI\ServiceLocator::get(), 0, argumentsSet('bitrix_sale_serviceLocator_codes'));

	override(\Bitrix\Main\DI\ServiceLocator::get(0), map([
		'sale.basketReservationHistory' => \Bitrix\Sale\Reservation\BasketReservationHistoryService::class,
		'sale.basketReservation' => \Bitrix\Sale\Reservation\BasketReservationService::class,
		'sale.reservation.settings' => \Bitrix\Sale\Reservation\Configuration\ReservationSettingsService::class,
		'sale.paysystem.manager' => \Bitrix\Sale\PaySystem\Manager::class,
		'sale.entityLabel' => \Bitrix\Sale\Label\EntityLabelService::class,
		'sale.basketItemCalculator' => \Bitrix\Sale\Public\Service\BasketItemCalculator::class,
		'sale.basketCalculator' => \Bitrix\Sale\Public\Service\BasketCalculator::class,
		'sale.orderCalculator' => \Bitrix\Sale\Public\Service\OrderCalculator::class,
		'sale.discountCalculator' => \Bitrix\Sale\Public\Service\DiscountCalculator::class,
		'sale.vatCalculator' => \Bitrix\Sale\Public\Service\VatCalculator::class,
		'sale.basketItemInputFactory' => \Bitrix\Sale\Public\Factory\BasketItemInputFactory::class,
		'sale.priceRounder' => \Bitrix\Sale\Public\Service\PriceRounder::class,
	]));
}
