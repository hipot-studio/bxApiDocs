<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\TariffGroupResolverInterface;
use Bitrix\Main\License;

class TariffGroupBoxResolver implements TariffGroupResolverInterface
{
	protected License $license;

	public function __construct()
	{
		$this->license = new License();
	}

	/**
	 * {@inheritDoc}
	 */
	public function resolve(): ?TariffGroup
	{
		if (in_array('Holding', $this->license->getCodes(), true))
		{
			return TariffGroup::LargeEnterprise;
		}

		return TariffGroup::Extendable;
	}
}
