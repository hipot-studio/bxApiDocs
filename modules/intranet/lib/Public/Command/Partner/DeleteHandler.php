<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Partner;

use Bitrix\Intranet\Internal\Integration\Main\Integrator\IntegratorInfoService;
use Bitrix\Main\Result;

class DeleteHandler
{
	public function __invoke(DeleteCommand $command): Result
	{
		$service = IntegratorInfoService::createByDefault();

		return $service->deletePartner($command->fromCheckout);
	}
}
