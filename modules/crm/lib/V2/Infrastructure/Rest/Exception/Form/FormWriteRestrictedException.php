<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The plan of the portal does not include writing CRM forms.
 *
 * Told apart from the refusal of a right on purpose: the same user on the same portal gets through
 * once the plan changes, and nothing in the roles of CRM would have helped.
 */
final class FormWriteRestrictedException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_FORM_WRITE_RESTRICTED';

	protected const STATUS = CRestServer::STATUS_PAYMENT_REQUIRED;

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_WRITE_RESTRICTED';
	}
}
