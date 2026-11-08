<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;

final class CompanyLogoHandler extends AbstractSystemFileFieldHandler
{
	public function getFieldName(): string
	{
		return 'LOGO';
	}

	protected function getEntityType(): EntityType
	{
		return EntityType::company();
	}

	protected function setFile(Item $item, ?File $file): void
	{
		$item->setLogo($file);
	}
}
