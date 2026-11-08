<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The CRM form works right now, and its removal was asked for without a confirmation.
 *
 * What stands in the way is the state of the form rather than a right of the client or a value of
 * the request, so the answer is a conflict: the very same request passes once it confirms the
 * removal. The status is shared with the mismatch of versions, and the code is what tells the two
 * conflicts of a removal apart.
 */
final class FormRemovalConfirmationRequiredException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_FORM_DELETE_CONFIRMATION_REQUIRED';

	protected const STATUS = CRestServer::STATUS_CONFLICT;

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_DELETE_CONFIRMATION_REQUIRED';
	}
}
