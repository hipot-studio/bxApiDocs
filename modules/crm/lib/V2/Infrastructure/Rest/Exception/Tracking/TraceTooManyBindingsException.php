<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Tracking;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The trace is bound to more items than `crm.tracking.trace.delete` takes in one request; nothing is
 * deleted.
 */
final class TraceTooManyBindingsException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_TRACKING_TRACE_TOO_MANY_BINDINGS';

	public function __construct(private readonly int $maxBindings)
	{
		parent::__construct();
	}

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_TRACKING_TRACE_TOO_MANY_BINDINGS';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#MAX#' => (string)$this->maxBindings];
	}
}
