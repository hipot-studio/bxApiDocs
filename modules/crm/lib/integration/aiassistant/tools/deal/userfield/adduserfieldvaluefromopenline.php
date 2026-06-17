<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Deal\UserField;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Integration\AiAssistant\Helper\OpenLineService;
use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Result;
use CCrmOwnerType;

final class AddUserFieldValueFromOpenLine extends BaseCrmTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly OpenLineService $openLineService,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return 'add_deal_userfield_value_from_openline';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Allows you to add values to a deal field
			To get a list of editable deal fields, use `list_deal_userfield_for_openline` tool
			This tool is intended for external Open Channel users.
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dealId' => [
					'description' => 'The identifier of the deal in which the value will be added',
					'type' => 'integer',
				],
				'fieldId' => [
					'description' => 'The ID of the field to set the value to. Use `list_deal_userfield_for_openline` to get a list of available fields.',
					'type' => 'string',
				],
				'fieldValue' => [
					'description' => 'Field value',
					'type' => 'string',
				],
			],
			'additionalProperties' => false,
			'required' => [
				'dealId',
				'fieldId',
				'fieldValue',
			],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$args = new AddUserFieldValueFromOpenLineDto($args);
		if ($args->hasValidationErrors())
		{
			return self::fail(Result::fail($args->getValidationErrors()));
		}

		$result = $this->openLineService->addUserFieldValue(
			userId: $userId,
			entityTypeId: CCrmOwnerType::Deal,
			entityId: $args->dealId,
			fieldId: $args->fieldId,
			fieldValue: $args->fieldValue,
		);

		if (!$result->isSuccess())
		{
			return self::fail($result);
		}

		return self::success();
	}

	protected static function fail(Result $result): string
	{
		return 'Failed to add value to the deal field: ' . implode(', ', $result->getErrorMessages());
	}

	protected static function success(): string
	{
		return 'The value was successfully added to the deal field.';
	}
}
