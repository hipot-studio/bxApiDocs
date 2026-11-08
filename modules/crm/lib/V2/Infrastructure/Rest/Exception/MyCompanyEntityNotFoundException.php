<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\EntityNotFoundException;

final class MyCompanyEntityNotFoundException extends EntityNotFoundException
{
	protected const STATUS = \CRestServer::STATUS_NOT_FOUND;

	protected function getClassWithPhrase(): string
	{
		return EntityNotFoundException::class;
	}
}
