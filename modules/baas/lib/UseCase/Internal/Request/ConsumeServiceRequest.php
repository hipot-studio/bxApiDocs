<?php

namespace Bitrix\Baas\UseCase\Internal\Request;

use \Bitrix\Baas;

class ConsumeServiceRequest
{
	public function __construct(
		public Baas\Entity\Service $service,
		public int $units,
		public bool $force,
	)
	{
	}
}
