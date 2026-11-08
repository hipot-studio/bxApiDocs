<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The form belongs to the portal itself and neither changes nor goes away.
 *
 * A code of its own rather than a plain access refusal: no right and no plan grants this, so a
 * caller told "forbidden" would keep looking for the permission that would let it through.
 */
final class SystemFormImmutableException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_FORM_SYSTEM_IMMUTABLE';

	protected const STATUS = CRestServer::STATUS_FORBIDDEN;

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_SYSTEM_IMMUTABLE';
	}
}
