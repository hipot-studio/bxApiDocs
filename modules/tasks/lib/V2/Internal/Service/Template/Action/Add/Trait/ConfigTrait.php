<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Trait;

use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;

trait ConfigTrait
{
	public function __construct(
		private readonly AddConfig $config,
	)
	{

	}
}
