<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM\Mapper;

use Bitrix\Booking\Entity\Resource\ResourceSku;
use Bitrix\Booking\Internals\Model\EO_ResourceSkuYandex;

class ResourceSkuYandexMapper
{
	public function convertFromOrm(EO_ResourceSkuYandex $ormResourceSkuYandex): ResourceSku
	{
		return (new ResourceSku())
			->setId($ormResourceSkuYandex->getSkuId())
		;
	}
}
