<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission;

use Bitrix\Rest\Internal\Exception\Admission\RestAccessDenialExceptionInterface;

interface GrantingStrategyInterface
{
	public function grants(RestAccessDenialExceptionInterface $denial): bool;
}
