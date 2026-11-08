<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The portal asks for a confirmed phone number before a CRM form may be written.
 *
 * The third of the three refusals the contract keeps apart, next to the plan and the right: what
 * unblocks it is a confirmation in the settings of the portal, so folding it into either of the
 * other two would send the caller to fix something that is already in order.
 */
final class FormPhoneVerificationRequiredException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_FORM_PHONE_VERIFICATION_REQUIRED';

	protected const STATUS = CRestServer::STATUS_FORBIDDEN;

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_PHONE_VERIFICATION_REQUIRED';
	}
}
