<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Config\ConvertConfig;

interface PrepareFieldInterface
{
	public function __construct(ConvertConfig $config);
	public function __invoke(Entity\Template $template, Entity\Task $task): Entity\Template;
}
