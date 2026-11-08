<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Rest;

final class CategoryOwnerAccessException extends \RuntimeException
{
	public function __construct()
	{
		parent::__construct('CRM item not found.');
	}
}
