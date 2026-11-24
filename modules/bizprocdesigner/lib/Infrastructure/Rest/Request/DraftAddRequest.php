<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\AddRequest;

/**
 * Writing a draft into the template the caller names.
 *
 * Extends AddRequest so that idempotency keeps working: the filter detects a write by the parameter
 * type, subclasses included ({@see \Bitrix\Rest\V3\Idempotency\IdempotencyService}), and the replay
 * check hashes the raw request body, so two writes of the same graph addressed to different templates
 * are told apart rather than answered from one another's stored response.
 */
final class DraftAddRequest extends AddRequest
{
	public ?int $templateId = null;
}
