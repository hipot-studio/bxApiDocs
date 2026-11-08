<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * A module the operation depends on is not available on this portal.
 *
 * Refused rather than reported as a fault of the server: nothing is broken and a repeat will fail
 * the same way until the portal gains the module, so the answer names the module instead of asking
 * to try again. The module id is a public name of a Bitrix24 module, not an internal detail.
 */
final class FormModuleUnavailableException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_FORM_MODULE_UNAVAILABLE';

	protected const STATUS = CRestServer::STATUS_FORBIDDEN;

	public function __construct(private readonly string $moduleId)
	{
		parent::__construct();
	}

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_MODULE_UNAVAILABLE';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#MODULE#' => $this->moduleId];
	}
}
