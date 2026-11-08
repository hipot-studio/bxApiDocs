<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The plan of the portal already keeps as many CRM forms working as it allows.
 *
 * Told apart from the refusal of the write itself on purpose: writing forms is allowed here, and
 * the client gets through by turning one of the working forms off instead of paying for anything.
 *
 * The numbers stay out of the answer: crm.form.configuration.get publishes how many forms work and
 * how many may work, and a second place naming them would part with the first one.
 */
final class FormActivationLimitReachedException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_FORM_ACTIVATION_LIMIT_REACHED';

	protected const STATUS = CRestServer::STATUS_PAYMENT_REQUIRED;

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_ACTIVATION_LIMIT_REACHED';
	}
}
