<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM\Mapper;

use Bitrix\Booking\Entity\Resource\ResourceSku;
use Bitrix\Booking\Internals\Model\EO_ResourceSku;

class ResourceSkuMapper
{
	public function convertFromOrm(EO_ResourceSku $ormResourceSku): ResourceSku
	{
		return (new ResourceSku())
			->setId($ormResourceSku->getSkuId())
		;
	}
}
