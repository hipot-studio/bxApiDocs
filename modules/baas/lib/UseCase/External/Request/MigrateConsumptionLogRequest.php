<?php

namespace Bitrix\Baas\UseCase\External\Request;

use \Bitrix\Baas;

class MigrateConsumptionLogRequest extends BaseRequest
{
	public function __construct(
		public Baas\UseCase\External\Entity\Server $server,
		public Baas\UseCase\External\Entity\Client $client,
		public Baas\Repository\ConsumptionRepositoryInterface $consumptionRepository,
	)
	{
		parent::__construct($server, $client);
	}
}
