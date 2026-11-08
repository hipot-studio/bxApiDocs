<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The answer with the value lists of the fields is larger than the action returns in one piece.
 *
 * The list is not truncated: a field set silently cut in half is worse than no answer at all, because the
 * agent cannot tell that it is looking at part of a type and will address the half it was given as the whole
 * (BRIEF § 6.3). Instead the refusal carries the way out in machine-readable form - the two sizes and the
 * select that fits - so the caller retries without the value lists rather than guessing what to drop.
 *
 * The details name sizes and field names of the request, never a value of the schema: what could not fit is
 * exactly what must not leak through the description of why it did not fit.
 *
 * The limit belongs to the read of an explicitly named type alone. The read of the bound type has never had
 * one and does not get one here.
 */
final class DocumentFieldResponseTooLargeException extends RestException implements SkipWriteToLogException
{
	use RussianMessageFallback;

	protected const STATUS = \CRestServer::STATUS_WRONG_REQUEST;

	/** The select that answers the same question without the value lists, which are what overflows. */
	private const RECOMMENDED_SELECT = ['id', 'name', 'editable'];

	public function __construct(
		private readonly int $maxBytes,
		private readonly int $actualBytes,
	) {
		parent::__construct();
	}

	public function output(?string $responseLanguage = null): array
	{
		return [
			...parent::output($responseLanguage),
			'details' => [
				'maxBytes' => $this->maxBytes,
				'actualBytes' => $this->actualBytes,
				'recommendedSelect' => self::RECOMMENDED_SELECT,
			],
		];
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_DOCUMENT_FIELD_RESPONSE_TOO_LARGE';
	}
}
