<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Config\TemplateMetadataLimits;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplateMetadata;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentTemplateMetadataValidationResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Sanitizer\BbCodeSanitizer;
use Bitrix\Main\Result;

final class AgentTemplateMetadataValidator
{
	public const NAME = 'name';
	public const DESCRIPTION = 'description';

	/** @param array<string, mixed> $fields */
	public function validate(
		array $fields,
		string $currentDescription = '',
	): AgentTemplateMetadataValidationResult
	{
		$result = new AgentTemplateMetadataValidationResult();
		$hasName = array_key_exists(self::NAME, $fields);
		$hasDescription = array_key_exists(self::DESCRIPTION, $fields);

		if ($hasName)
		{
			$result->addErrors(self::validateName($fields[self::NAME])->getErrors());
		}
		if ($hasDescription)
		{
			$result->addErrors(self::validateDescription($fields[self::DESCRIPTION])->getErrors());
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		$description = null;
		if ($hasDescription)
		{
			$description = self::acceptedDescription((string)$fields[self::DESCRIPTION], $currentDescription);
			if ($description === null)
			{
				return $result->addError(GraphError::at(
					self::DESCRIPTION,
					self::DESCRIPTION . ' could not be cleaned up',
				));
			}
		}

		return new AgentTemplateMetadataValidationResult(
			new AgentTemplateMetadata(
				name: $hasName ? (string)$fields[self::NAME] : null,
				description: $description,
			),
		);
	}

	private static function validateName(mixed $name): Result
	{
		$result = new Result();

		if (!is_string($name))
		{
			return $result->addError(GraphError::at(self::NAME, self::NAME . ' is incorrect type'));
		}

		if (self::trimUnicodeSpaces($name) === '')
		{
			return $result->addError(GraphError::at(self::NAME, self::NAME . ' should be not empty string'));
		}

		if (mb_strlen($name) > TemplateMetadataLimits::NAME_MAX_LENGTH)
		{
			return $result->addError(GraphError::at(
				self::NAME,
				self::NAME . ' is longer than ' . TemplateMetadataLimits::NAME_MAX_LENGTH . ' characters',
			));
		}

		return $result;
	}

	private static function validateDescription(mixed $description): Result
	{
		$result = new Result();

		if (!is_string($description))
		{
			return $result->addError(
				GraphError::at(self::DESCRIPTION, self::DESCRIPTION . ' is incorrect type'),
			);
		}

		if (mb_strlen($description) > TemplateMetadataLimits::DESCRIPTION_MAX_LENGTH)
		{
			return $result->addError(GraphError::at(
				self::DESCRIPTION,
				self::DESCRIPTION . ' is longer than '
					. TemplateMetadataLimits::DESCRIPTION_MAX_LENGTH . ' characters',
			));
		}

		return $result;
	}

	private static function acceptedDescription(string $description, string $currentDescription): ?string
	{
		return $description === $currentDescription
			? $description
			: BbCodeSanitizer::sanitizeTemplateDescription(
				$description,
				TemplateMetadataLimits::DESCRIPTION_MAX_LENGTH,
			)
		;
	}

	private static function trimUnicodeSpaces(string $value): string
	{
		return preg_replace('/^[\pZ\pC\s]+|[\pZ\pC\s]+$/u', '', $value) ?? $value;
	}
}
