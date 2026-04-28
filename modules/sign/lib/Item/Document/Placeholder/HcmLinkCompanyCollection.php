<?php

namespace Bitrix\Sign\Item\Document\Placeholder;

use Bitrix\Sign\Item\Collection;

/**
 * @extends Collection<HcmLinkCompany>
 */
final class HcmLinkCompanyCollection extends Collection
{
	protected function getItemClassName(): string
	{
		return HcmLinkCompany::class;
	}
}
