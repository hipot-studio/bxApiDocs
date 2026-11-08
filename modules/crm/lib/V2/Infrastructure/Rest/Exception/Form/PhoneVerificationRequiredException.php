<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The portal demands a verified phone number before a form may be embedded anywhere.
 *
 * The phrase and the machine code come from this class, so the restriction of the portal stays
 * distinguishable from a plain refusal of access.
 */
final class PhoneVerificationRequiredException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = CRestServer::STATUS_FORBIDDEN;

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_EMBEDDING_PHONE_VERIFICATION_REQUIRED';
	}
}
