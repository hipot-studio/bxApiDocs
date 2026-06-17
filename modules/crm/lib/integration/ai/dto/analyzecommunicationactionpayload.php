<?php

namespace Bitrix\Crm\Integration\AI\Dto;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AnalyzeCommunicationActionPayload extends Dto
{
	private const DEFAULT_SUBJECT_MAX_LENGTH = 255;

	public ?string $title = null;
	public ?string $description = null;
	public ?string $responsiblePerson = null;
	public ?string $deadline = null;

	public function getSubject(int $maxLength = self::DEFAULT_SUBJECT_MAX_LENGTH): string
	{
		foreach ([$this->title, $this->description] as $candidate)
		{
			$candidate = trim((string)$candidate);
			if ($candidate !== '')
			{
				return mb_substr($candidate, 0, $maxLength);
			}
		}

		return '';
	}

	protected function getValidators(array $fields): array
	{
		return [
			new class($this) extends Validator {
				public function validate(array $fields): Result
				{
					$result = new Result();
					if (empty($fields['title']) && empty($fields['description']))
					{
						$result->addError(new Error('Either title or description must be set'));
					}

					return $result;
				}
			},
		];
	}
}
