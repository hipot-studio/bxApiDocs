<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use CRestServer;

final class FormNotFoundException extends EntityNotFoundException
{
	protected const STATUS = CRestServer::STATUS_NOT_FOUND;

	protected function getClassWithPhrase(): string
	{
		return EntityNotFoundException::class;
	}
}
