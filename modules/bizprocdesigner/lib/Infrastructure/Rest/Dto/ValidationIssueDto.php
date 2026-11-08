<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Single problem of a validated graph (DTO-02).
 *
 * The reader is an external agent, not an end user: the message is the domain validator's own text and
 * stays as it is.
 *
 * blockId, code and details are nullable rather than conditionally emitted: a problem the validator could
 * not bind to a block, a message no error class has been extracted for yet, or a failure with nothing to
 * explain, still answers with the key carrying null, so the agent may read all five fields of every issue
 * unconditionally.
 */
class ValidationIssueDto extends Dto
{
	#[Description('Full address of the problem in the submitted graph, e.g. "blocks.3.settings.1.name". Empty when the problem has no address.')]
	public string $path;

	#[Description('Block of the submitted graph the problem belongs to. Null when the problem is not bound to a block.')]
	public ?string $blockId;

	#[Description('Machine readable code of the domain error class. Null when no class is extracted for the message.')]
	public ?string $code;

	#[Description('Message of the domain validator.')]
	public string $message;

	/** Untyped keys on purpose: which dictionaries a problem carries depends on the failure it names. */
	#[Description(
		'Server-side dictionaries explaining the problem: allowedKeys, requiredKeys, allowedOutputs,'
		. ' constraint, maxBytes, actualBytes, recommendedSelect. Null when the problem carries none.'
	)]
	public ?array $details;
}
