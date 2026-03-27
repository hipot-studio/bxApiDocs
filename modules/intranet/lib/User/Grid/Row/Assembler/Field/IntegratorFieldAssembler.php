<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field;

use Bitrix\Intranet\Internal\Entity\User\PartnerInfoCollection;
use Bitrix\Intranet\Internal\Repository\User\IntegratorInfoRepository;

class IntegratorFieldAssembler extends CustomUserFieldAssembler
{
	private static ?PartnerInfoCollection $integratorInfo = null;

	protected function prepareColumn($value): mixed
	{
		$userId = $value['ID'];
		$value = $this->getIntegratorInfo()->findById((int)$userId)?->integratorName ?? '';

		return htmlspecialcharsbx($value);
	}

	private function getIntegratorInfo(): PartnerInfoCollection
	{
		if (!self::$integratorInfo)
		{
			self::$integratorInfo = (new IntegratorInfoRepository())->getList();
		}

		return self::$integratorInfo;
	}
}
