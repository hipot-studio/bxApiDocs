<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field;

use Bitrix\Crm\V2\Infrastructure\Rest\Exception\FieldRequirement\ConfigurationConflictException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\FieldRequirement\ContextNotFoundException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\FieldRequirement\WriteFailureException;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Error as RequirementError;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Exception as RequirementException;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\FeatureNotAvailableOnCurrentPlanException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException;

final class RequirementErrorMapper
{
	public function mapResultError(Error $error, string $fieldId): RestException
	{
		$code = $error->getCode();
		$requirementError = is_string($code) ? RequirementError::tryFrom($code) : null;

		return match ($requirementError) {
			RequirementError::FIELD_REQUIREMENT_NOT_SUPPORTED => $this->validationError(
				'Field requirement is not supported for this field.',
				'fieldId',
			),
			RequirementError::REQUIRED_STAGE_IDS_NULL,
			RequirementError::REQUIRED_STAGE_ID_INVALID => $this->validationError(
				'requiredStageIds must be an array of non-empty strings.',
				'requiredStageIds',
			),
			RequirementError::STAGES_NOT_SUPPORTED => $this->validationError(
				'Stage requirements are not supported in this context.',
				'requiredStageIds',
			),
			RequirementError::STAGE_NOT_FOUND => $this->validationError(
				'A required stage was not found in this context.',
				'requiredStageIds',
			),
			RequirementError::STAGE_SELECTION_NOT_REPRESENTABLE => $this->validationError(
				'The selected stages cannot be represented by a field requirement.',
				'requiredStageIds',
			),
			RequirementError::STAGE_REQUIREMENT_NOT_AVAILABLE => new class() extends
				FeatureNotAvailableOnCurrentPlanException {
				protected function getLocalMessage(string $languageCode): string
				{
					return 'This feature is not available on your current plan.';
				}

				protected function getClassWithPhrase(): string
				{
					return FeatureNotAvailableOnCurrentPlanException::class;
				}
			},
			RequirementError::ACCESS_DENIED,
			RequirementError::FIELD_NOT_FOUND,
			RequirementError::ENTITY_TYPE_NOT_FOUND,
			RequirementError::ENTITY_TYPE_NOT_SUPPORTED,
			RequirementError::CATEGORY_NOT_FOUND,
			RequirementError::CATEGORY_ENTITY_TYPE_MISMATCH,
			RequirementError::CONFIGURATION_CONFLICT,
			RequirementError::CATEGORY_ID_REQUIRED,
			RequirementError::CATEGORY_ID_NOT_ALLOWED,
			RequirementError::CATEGORY_ID_INVALID => $this->map(new RequirementException($requirementError), $fieldId),
			default => new WriteFailureException(),
		};
	}

	private function validationError(string $message, string $field): RequestValidationException
	{
		return new class([new Error($message, $field)]) extends RequestValidationException {
			protected function getLocalMessage(string $languageCode): string
			{
				return 'Request validation failed.';
			}
		};
	}

	public function map(RequirementException $exception, string $fieldId): RestException
	{
		return match ($exception->getError()) {
			RequirementError::ACCESS_DENIED => new AccessDeniedException(),
			RequirementError::FIELD_NOT_FOUND => new FieldNotFoundException($fieldId),
			RequirementError::ENTITY_TYPE_NOT_FOUND,
			RequirementError::ENTITY_TYPE_NOT_SUPPORTED,
			RequirementError::CATEGORY_NOT_FOUND,
			RequirementError::CATEGORY_ENTITY_TYPE_MISMATCH => new ContextNotFoundException(),
			RequirementError::CONFIGURATION_CONFLICT => new ConfigurationConflictException(),
			RequirementError::CATEGORY_ID_REQUIRED,
			RequirementError::CATEGORY_ID_NOT_ALLOWED,
			RequirementError::CATEGORY_ID_INVALID => new RequestValidationException([
				new Error('Invalid category ID.', 'categoryId'),
			]),
			default => throw $exception,
		};
	}
}
