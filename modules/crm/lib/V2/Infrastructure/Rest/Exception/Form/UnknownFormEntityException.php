<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The filter of the dictionary of fields names an entity the dictionary knows nothing about.
 *
 * A name the portal has no entity under is a mistake of the caller and not an empty slice of the
 * dictionary: answering it with no field would leave a client rebuilding a form editor unable to
 * tell a misspelled entity from one that simply has no field to offer.
 */
final class UnknownFormEntityException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = CRestServer::STATUS_WRONG_REQUEST;

	/**
	 * @param list<string> $entityNames
	 */
	public function __construct(private readonly array $entityNames)
	{
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_UNKNOWN_ENTITY';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#ENTITY_NAMES#' => implode(', ', $this->entityNames)];
	}
}
