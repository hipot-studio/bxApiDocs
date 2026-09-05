<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Validation;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Result;

class ValidationService
{
	public function __construct(
		protected ConfigValidationService $configValidationService,
		protected BlockValidationService $blockValidationService,
		protected FileValidationService $fileValidationService,
	)
	{}

	public function validateNew(array $builderData, Chat $chat): Result
	{
		return $this->validateInternal($builderData, $chat);
	}

	public function validateExisting(array $builderData): Result
	{
		return $this->validateInternal($builderData);
	}

	protected function validateInternal(array $builderData, ?Chat $chat = null): Result
	{
		$result = new Result();

		$elementsResult = $this->validateElements($builderData);
		if (!$elementsResult->isSuccess())
		{
			return $elementsResult;
		}

		$configResult = $this->configValidationService->validate($builderData);
		if (!$configResult->isSuccess())
		{
			return $configResult;
		}

		$builderData = $configResult->getResult();

		$blocks = $builderData['elements'];

		if (isset($chat))
		{
			$fileResult = $this->fileValidationService->validate($blocks, $chat);
			if (!$fileResult->isSuccess())
			{
				return $fileResult;
			}

			$blocks = $fileResult->getResult();
		}

		foreach ($blocks as $key => $blockData)
		{
			$blockResult = $this->blockValidationService->validate($blockData);
			if (!$blockResult->isSuccess())
			{
				return $blockResult;
			}

			$builderData['elements'][$key] = $blockResult->getResult();
		}

		return $result->setResult($builderData);
	}

	protected function validateElements(array $builderData): Result
	{
		$result = new Result();

		if (isset($builderData['elements']) && !is_array($builderData['elements']))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_ELEMENTS));
		}

		if (empty($builderData['elements']))
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_ELEMENTS));
		}

		return $result;
	}
}
