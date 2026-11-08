<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Public\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Compact representation of CRM items in mobile entity tabs.
 */
final class CompactListItem extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
