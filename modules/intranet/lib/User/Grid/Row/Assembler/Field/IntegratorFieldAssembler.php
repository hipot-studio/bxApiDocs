<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field;

use Bitrix\Bitrix24\Internal\Entity\User\PartnerInfoCollection;
use Bitrix\Intranet\Internal\Integration\Bitrix24\Integrator\PartnerInfo;
use Bitrix\Main\Loader;

class IntegratorFieldAssembler extends CustomUserFieldAssembler
{
	private static ?PartnerInfoCollection $integratorInfo = null;

	protected function prepareColumn($value): mixed
	{
		$userId = $value['ID'];
		$integratorInfo = $this->getIntegratorInfo();
		$value = $integratorInfo?->findById((int)$userId)?->integratorName ?? '';

		return htmlspecialcharsbx($value);
	}

	private function getIntegratorInfo(): ?PartnerInfoCollection
	{
		if (!self::$integratorInfo)
		{
			if (!Loader::includeModule('bitrix24'))
			{
				return null;
			}

			self::$integratorInfo = (new PartnerInfo())->getList();
		}

		return self::$integratorInfo;
	}
}
