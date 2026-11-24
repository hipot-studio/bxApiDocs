<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

/**
 * The framework refusal of access, answered with the builder failure code.
 */
class BuilderAccessDeniedException extends AccessDeniedException implements BuilderRefusal
{
	use FailureCodeAsRegistryCode;

	private const REASON_PHRASES = [
		'no_bi_profile' => 'BICONNECTOR_REST_BUILDER_ACCESS_DENIED_NO_BI_PROFILE',
		'chart_author_required' => 'BICONNECTOR_REST_BUILDER_ACCESS_DENIED_CHART_AUTHOR_REQUIRED',
	];
	private readonly ?string $reason;

	public function __construct(?\Throwable $previous = null, ?string $status = null, ?string $reason = null)
	{
		$this->reason = self::isPublicReason($reason) ? $reason : null;
		parent::__construct($previous, $status);
	}

	public static function isPublicReason(mixed $reason): bool
	{
		return is_string($reason) && isset(self::REASON_PHRASES[$reason]);
	}

	public function output(?string $responseLanguage = null): array
	{
		$output = parent::output($responseLanguage);
		if ($this->reason !== null)
		{
			$output['data'] = ['reason' => $this->reason];
		}

		return $output;
	}

	protected function getMessagePhraseCode(): string
	{
		return $this->reason === null
			? parent::getMessagePhraseCode()
			: self::REASON_PHRASES[$this->reason]
		;
	}

	public function getFailureCode(): FailureCode
	{
		return FailureCode::AccessDenied;
	}

	protected function getClassWithPhrase(): string
	{
		return $this->reason === null ? AccessDeniedException::class : self::class;
	}
}
