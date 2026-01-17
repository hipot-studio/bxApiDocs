<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Control\TemplateDependence;

class UpdateDependencies
{
	public function __invoke(array $fields): void
	{
		(new TemplateDependence($fields['ID']))->set($fields);
	}
}
