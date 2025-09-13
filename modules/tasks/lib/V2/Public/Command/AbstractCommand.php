<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command;

use Bitrix\Tasks\V2\Internal\Result\Result;

abstract class AbstractCommand extends \Bitrix\Main\Command\AbstractCommand
{
	abstract protected function execute(): Result;
}
