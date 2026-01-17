<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

interface PrepareFieldInterface
{
	public function __invoke(array $fields): array;
}
