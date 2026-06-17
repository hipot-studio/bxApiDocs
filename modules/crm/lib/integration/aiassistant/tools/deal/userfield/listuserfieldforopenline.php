<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Deal\UserField;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Integration\AiAssistant\Helper\OpenLineService;
use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Result;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;

final class ListUserFieldForOpenLine extends BaseCrmTool
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
		return 'list_deal_userfield_for_openline';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Allows you to get a list of deal fields that can be populated with values.
			To populate values, use `add_deal_userfield_value_from_openline` tool
			This tool is intended for external Open Channel users.
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dealId' => [
					'description' => 'CRM deal ID.',
					'type' => 'integer',
				],
			],
			'additionalProperties' => false,
			'required' => [
				'dealId',
			],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$args = new ListUserFieldForOpenLineDto($args);
		if ($args->hasValidationErrors())
		{
			return self::fail(Result::fail($args->getValidationErrors()));
		}

		$result = $this->openLineService->getEditableUserFieldList(
			userId: $userId,
			entityTypeId: CCrmOwnerType::Deal,
			entityId: $args->dealId,
		);
		if (!$result->isSuccess())
		{
			return self::fail($result);
		}

		return self::success($result->get('fields'));
	}

	protected static function fail(Result $result): string
	{
		return 'Failed to get list of fields for deal: ' . implode(', ', $result->getErrorMessages());
	}

	protected static function success(array $fields): string
	{
		return Json::encode($fields);
	}
}
