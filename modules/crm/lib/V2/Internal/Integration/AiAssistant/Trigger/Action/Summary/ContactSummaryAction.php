<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary;

/**
 * AI summary chat about a CRM contact.
 *
 * @internal
 */
final class ContactSummaryAction extends AbstractClientSummaryAction
{
	/**
	 * Reaches the engine database and the client code, so it never changes after the delivery:
	 * a renamed code loses every chat already reused by it.
	 */
	public const TRIGGER_CODE = 'crm.client-summary-contact';

	public const CHAT_ENTITY_TYPE = 'CRM_CONTACT';

	public static function getCode(): string
	{
		return self::TRIGGER_CODE;
	}

	protected function chatEntityType(): string
	{
		return self::CHAT_ENTITY_TYPE;
	}
}
