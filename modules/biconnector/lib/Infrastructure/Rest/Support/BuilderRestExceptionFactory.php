<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Support;

use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\BuilderAccessDeniedException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\BuilderEntityNotFoundException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\BuilderFeatureNotAvailableException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\BuilderInstanceOutdatedException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\BuilderRequestValidationException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\DestructiveBudgetException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\DomainRuleException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\GuideSectionNotFoundException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\GuideSectionUnavailableException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\LayoutOperationRefusedException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\NotCustomReportException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\PublishedReportException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\RateLimitExceededException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\ReportUnavailableException;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\VersionConflictException;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\RestException;

/**
 * Turns a refused builder operation into the REST V3 exception chosen by the code of its first error.
 * Every refusal answers the FailureCode value as its code (BuilderRefusal).
 *
 * A result without a FailureCode is not a refusal but a defect of the operation: it leaves as an internal
 * error that reaches the exception log with the original code and text.
 */
final class BuilderRestExceptionFactory
{
	/**
	 * @param int|null $entityId The record the caller asked for; a NotFound refusal names it. Without it,
	 * a NotFound refusal must name a guide section in its data.
	 */
	public static function from(Result $result, ?int $entityId = null): RestException
	{
		$error = $result->getErrors()[0] ?? null;
		$code = $error === null ? null : FailureCode::tryFrom((string)$error->getCode());
		$data = $result->getData();

		return match ($code)
		{
			FailureCode::AccessDenied => new BuilderAccessDeniedException(),
			FailureCode::NotFound => match (true)
			{
				$entityId !== null => new BuilderEntityNotFoundException($entityId),
				self::isGuideSection($data) => new GuideSectionNotFoundException($data),
				default => self::defect('NotFound refusal without the id of the requested record', $error),
			},
			FailureCode::InvalidArgument => array_key_exists('operation', $data)
				? new LayoutOperationRefusedException($data)
				: new BuilderRequestValidationException([new Error($error->getMessage())]),
			FailureCode::RateLimit => new RateLimitExceededException($data),
			FailureCode::PublishedReport => new PublishedReportException($data),
			FailureCode::NotCustom => new NotCustomReportException($data),
			FailureCode::DomainRule => new DomainRuleException($data),
			FailureCode::VersionConflict => new VersionConflictException($data),
			FailureCode::DestructiveBudget => new DestructiveBudgetException($data),
			FailureCode::Unavailable => self::isGuideSection($data)
				? new GuideSectionUnavailableException($data)
				: new ReportUnavailableException($data),
			FailureCode::FeatureDisabled => Refusal::isOutdatedInstance($result)
				? new BuilderInstanceOutdatedException()
				: new BuilderFeatureNotAvailableException(),
			null => self::defect('Builder operation refused without a known failure code', $error),
		};
	}

	/**
	 * A refusal about a section of the agent guide names the section by its string id.
	 */
	private static function isGuideSection(array $data): bool
	{
		return is_string($data['section'] ?? null);
	}

	private static function defect(string $reason, ?Error $error): InternalException
	{
		$details = $error === null
			? 'the result carries no error'
			: "code '{$error->getCode()}': {$error->getMessage()}";

		return new InternalException(new SystemException("{$reason}, {$details}."));
	}
}
