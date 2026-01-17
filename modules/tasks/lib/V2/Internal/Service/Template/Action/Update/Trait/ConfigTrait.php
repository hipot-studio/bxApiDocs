<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Trait;

use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;

trait ConfigTrait
{
	public function __construct(
		private readonly UpdateConfig $config,
	)
	{

	}
}
