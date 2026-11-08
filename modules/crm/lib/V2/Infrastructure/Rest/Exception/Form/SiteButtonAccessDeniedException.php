<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The user may read CRM forms but not the site widgets a form can be placed into.
 *
 * The widget is a permission entity of its own in the role model of CRM, so this refusal keeps its
 * own class: a caller must be able to tell it from a plain refusal to read forms.
 */
final class SiteButtonAccessDeniedException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = CRestServer::STATUS_FORBIDDEN;

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_SITE_BUTTON_ACCESS_DENIED';
	}
}
