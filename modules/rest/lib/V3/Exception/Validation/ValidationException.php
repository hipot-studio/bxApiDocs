<?php

namespace Bitrix\Rest\V3\Exception\Validation;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\DefaultLanguage;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * This class is used for displaying validation errors.
 * It supports outputting multiple error messages linked to specific fields.
 */
abstract class ValidationException extends RestException implements SkipWriteToLogException
{
	/**
	 * @param Error[] $errors
	 */
	public function __construct(
		protected array $errors,
	) {
		parent::__construct();
	}

	public function output($responseLanguage = null): array
	{
		$out = parent::output($responseLanguage);

		$validationItems = [];

		foreach ($this->errors as $error)
		{
			$validationItem = [
				'message' => $error->getLocalizableMessage()?->localize($responseLanguage ?: DefaultLanguage::get()) ?? $error->getMessage(),
			];

			if (!empty($error->getCode()))
			{
				$validationItem['field'] = $error->getCode();
			}

			$validationItems[] = $validationItem;

		}

		$out['validation'] = $validationItems;

		return $out;
	}
}
