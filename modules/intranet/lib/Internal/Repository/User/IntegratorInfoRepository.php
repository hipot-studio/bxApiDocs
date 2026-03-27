<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository\User;

use Bitrix\Intranet\Internal\Entity\User\PartnerInfo;
use Bitrix\Intranet\Internal\Entity\User\PartnerInfoCollection;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

class IntegratorInfoRepository
{
	public function getList(): PartnerInfoCollection
	{
		$option = Option::get('intranet', 'integratorInfo');

		try
		{
			return PartnerInfoCollection::createByOption(Json::decode($option));
		}
		catch (ArgumentException)
		{
			return new PartnerInfoCollection();
		}
	}

	public function add(PartnerInfo $partnerInfo): void
	{
		$partnerInfoCollection = $this->getList();
		$partnerInfoCollection->add($partnerInfo);
		$this->saveList($partnerInfoCollection);
	}

	protected function saveList(PartnerInfoCollection $partnerInfoCollection): void
	{
		Option::set('intranet', 'integratorInfo', Json::encode($partnerInfoCollection->toArray()));
	}
}
