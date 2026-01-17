<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache\BlockDescriptionCache;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class AgentBlockValidator
{
	private readonly BlockDescriptionCache $blockDescriptionCache;
	private readonly AgentBlockSettingsValidator $settingsValidator;
	private ?AgentBlock $validBlock = null;
	private ?string $id = null;

	public function __construct(
		BlockDescriptionCache $blockDescriptionCache,
		?AgentBlockSettingsValidator $settingsValidator = null,
	) {
		$this->blockDescriptionCache = $blockDescriptionCache;
		$this->settingsValidator = $settingsValidator ?? new AgentBlockSettingsValidator();
	}

	public function validate(
		mixed $block,
		DocumentDescription $documentType,
		array $blackListIds = [],
		string $path = '',
	): Result
	{
		if (!is_array($block))
		{
			return (new Result())->addError(new Error("$path should be object"));
		}
		$this->validBlock = null;
		$this->id = null;

		$id = $block['id'] ?? null;
		$title = $block['title'] ?? null;
		$type = $block['type'] ?? null;
		$settings = $block['settings'] ?? null;

		$result = new Result();
		$result->addErrors($this->validateId($id, $path, $blackListIds)->getErrors());
		$result->addErrors($this->validateTitle($title, $path)->getErrors());
		$typeValidationResult = $this->validateType($type, $path, $documentType);
		$blockDetail = $typeValidationResult instanceof BlockSettingsResult ? $typeValidationResult->blockDetail : null;
		$result->addErrors($typeValidationResult->getErrors());
		$settingValidationResult = $this->settingsValidator->validate(
			settings: $settings,
			path: "$path.settings",
			blockTypeDetail: $blockDetail,
		);
		$result->addErrors($settingValidationResult->getErrors());

		if ($result->isSuccess() && $this->settingsValidator->getValidSettings())
		{
			$this->validBlock = new AgentBlock(
				type: $type,
				title: $title,
				id: $id,
				settings: $this->settingsValidator->getValidSettings(),
			);
		}

		$this->id = $id;

		return $result;
	}

	private function validateId(mixed $id, string $path, array $blackListIds): Result
	{
		if (!is_string($id) || $id === '')
		{
			return (new Result())->addError(new Error("{$path}.id should be not empty string"));
		}

		if (in_array($id, $blackListIds, true))
		{
			return (new Result())->addError(new Error("{$path}.id should be unique value"));
		}

		return new Result();
	}

	private function validateTitle(mixed $title, string $path): Result
	{
		if (!is_string($title) || $title === '')
		{
			return (new Result())->addError(new Error("{$path}.title should be not empty string"));
		}

		return new Result();
	}

	private function validateType(mixed $type, string $path, DocumentDescription $documentType): Result|BlockSettingsResult
	{
		if (!is_string($type) || $type === '')
		{
			return (new Result())->addError(new Error("{$path}.type should be not empty string"));
		}

		$blockDetail = $this->blockDescriptionCache->get($type, $documentType);
		if ($blockDetail === null)
		{
			return (new Result())->addError(new Error("{$path}.type is incorrect type"));
		}

		return new BlockSettingsResult($blockDetail);
	}

	public function getValidBlock(): ?AgentBlock
	{
		return $this->validBlock;
	}

	public function getId(): mixed
	{
		return $this->id;
	}
}