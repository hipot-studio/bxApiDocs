<?php

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Feature;
use Bitrix\Main\Type\DateTime;

interface ProviderInterface
{
	public function getFeatureSummary(int $userId, DateTime $from, DateTime $to): Feature;
}
