<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\UserField;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\UserField\Types\DoubleType;
use Bitrix\Main\UserField\Types\EnumType;
use Bitrix\Main\UserField\Types\IntegerType;
use Bitrix\Main\UserField\Types\StringType;
use Bitrix\Main\Validation\Rule\PositiveNumber;

abstract class BaseUserFieldTool extends BaseCrmTool
{
	protected const EDITABLE_USER_FIELD_TYPES = [
		StringType::USER_TYPE_ID => true,
		IntegerType::USER_TYPE_ID => true,
		DoubleType::USER_TYPE_ID => true,
		EnumType::USER_TYPE_ID => true,
	];

	#[PositiveNumber(errorMessage: 'User ID must be a positive integer')]
	protected int $userId;

	abstract protected function innerExecute(): string;

	protected function parseInput(array $args): void
	{
		$this->userId = (int)($args['userId'] ?? 0);
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$this->parseInput([
			'userId' => $userId,
			...$args,
		]);

		$validator = ServiceLocator::getInstance()->get('main.validation.service');
		$validationResult = $validator->validate($this);
		if (!$validationResult->isSuccess())
		{
			return 'Validation errors: ' . implode(', ', $validationResult->getErrorMessages());
		}

		return $this->innerExecute();
	}

	protected function isEditableUserFieldType(string $userFieldType): bool
	{
		return isset(self::EDITABLE_USER_FIELD_TYPES[$userFieldType]);
	}

	protected function getEditableUserFieldList(int $entityTypeId): array
	{
		$userFields = Container::getInstance()->getFactory($entityTypeId)?->getUserFields();
		return array_filter(
			$userFields ?? [],
			function (array $userField) {
				return $this->isEditableUserFieldType($userField['USER_TYPE_ID']);
			},
		);
	}
}
