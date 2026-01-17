<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Trait;

use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Config\ConvertConfig;

trait ConfigTrait
{
	public function __construct(
		private readonly ConvertConfig $config,
	)
	{

	}
}
