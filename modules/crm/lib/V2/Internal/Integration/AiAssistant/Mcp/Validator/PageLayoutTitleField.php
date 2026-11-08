<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class PageLayoutTitleField extends Validator
{
	public function __construct(
		Dto $dto,
		private readonly bool $isRequired,
	)
	{
		parent::__construct($dto);
	}

	public function validate(array $fields): Result
	{
		$pageLayout = $fields['pageLayout'] ?? null;
		if (!is_array($pageLayout))
		{
			return new Result();
		}

		if (!$this->isRequired)
		{
			$result = new Result();
			if (array_key_exists('title', $pageLayout))
			{
				$result->addError(new Error(
					'The pageLayout.title field must not be passed for common or personal page layouts.',
					'FIELD_MUST_NOT_BE_PROVIDED',
				));
			}

			return $result;
		}

		$result = new Result();
		$validators = [
			new RequiredField($this->dto, 'title'),
			new StringField($this->dto, 'title'),
			new NotEmptyField($this->dto, 'title'),
		];
		foreach ($validators as $validator)
		{
			$result->addErrors($validator->validate($pageLayout)->getErrors());
		}

		return $result;
	}
}
