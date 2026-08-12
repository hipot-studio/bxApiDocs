<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Base;

use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;

interface RuntimeRequestQuotaProvider
{
	public function getRuntimeRequestQuota(Site $siteData): ?RequestQuotaDto;
}
