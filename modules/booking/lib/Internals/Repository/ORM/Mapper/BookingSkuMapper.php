<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM\Mapper;

use Bitrix\Booking\Entity\Booking\BookingSku;
use Bitrix\Booking\Internals\Model\EO_BookingSku;

class BookingSkuMapper
{
	public function convertFromOrm(EO_BookingSku $ormSku): BookingSku
	{
		return (new BookingSku())
			->setId($ormSku->getSkuId())
			->setProductRowId($ormSku->getProductRowId())
		;
	}
}
