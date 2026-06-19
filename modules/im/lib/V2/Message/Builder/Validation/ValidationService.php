<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Validation;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Result;

class ValidationService
{
	public function __construct(
		protected ConfigValidationService $configValidationService,
		protected BlockValidationService $blockValidationService,
	)
	{}

	public function validate(array $builderData): Result
	{
		$result = new Result();

		if (!Features::isMessageBuilderAvailable())
		{
			return $result->addError((new BuilderError(BuilderError::BUILDER_NOT_AVAILABLE)));
		}

		return $this->validateInternal($builderData);
	}

	protected function validateInternal(array $builderData): Result
	{
		$result = $this->configValidationService->validate($builderData);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$builderData = $result->getResult();

		$blocks = $builderData['blocks'] ?? [];
		foreach ($blocks as $key => $blockData)
		{
			$result = $this->blockValidationService->validate($blockData);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$builderData['blocks'][$key] = $result->getResult();
		}

		return $result->setResult($builderData);
	}
}
