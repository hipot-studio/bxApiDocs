<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Config\ConvertConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field\PrepareBaseFields;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field\PrepareOptionFields;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field\PreparePipeline;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field\PrepareReplication;

class ToTemplateConverter
{
	public function __construct(
		private readonly ConvertConfig $config,
	)
	{
	}

	public function __invoke(Entity\Task $task): Entity\Template
	{
		$template = new Entity\Template();

		$pipeline = new PreparePipeline($this->config, [
			PrepareBaseFields::class,
			PrepareOptionFields::class,
			PrepareReplication::class,
		]);

		return $pipeline($template, $task);
	}
}
