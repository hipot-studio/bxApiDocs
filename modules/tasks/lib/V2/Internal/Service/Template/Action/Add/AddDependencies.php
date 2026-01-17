<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\Control\TemplateDependence;

class AddDependencies
{
	public function __invoke(array $fields): void
	{
		(new TemplateDependence($fields['ID']))->add($fields);
	}
}
