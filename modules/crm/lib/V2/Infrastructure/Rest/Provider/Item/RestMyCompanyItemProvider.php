<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Provider\Item;

use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\AbstractItemProvider;

final class RestMyCompanyItemProvider extends RestItemProvider
{
	public function __construct(int $userId, ?AbstractItemProvider $provider = null)
	{
		parent::__construct(EntityType::company(), $userId, $provider);
	}

	protected function isCompanyVisible(Item $item): bool
	{
		return $item instanceof Company && $item->getIsMyCompany() === true;
	}

	protected function getListRestrictions(): array
	{
		$restrictions = parent::getListRestrictions();
		$restrictions['=isMyCompany'] = 'Y';

		return $restrictions;
	}
}
