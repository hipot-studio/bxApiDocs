<?php

namespace Bitrix\Crm\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Crm\Integration\AiAssistant\Tools\Deal;
use Bitrix\Crm\Integration\AiAssistant\Tools\Lead;
use Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class OpenLineToolSet extends BaseToolSet
{
	public function canList(int $userId): bool
	{
		return $this->isEnabled();
	}

	public function canRun(int $userId): bool
	{
		return $this->isEnabled();
	}

	private function isEnabled(): bool
	{
		return Loader::includeModule('imopenlines')
			&& class_exists(AiOpenLinesOperatorAgentFeature::class)
			&& ServiceLocator::getInstance()->get(AiOpenLinesOperatorAgentFeature::class)?->isAvailable()
		;
	}

	public function getCode(): string
	{
		return 'openline';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM openlines tools',
			'Public CRM open lines tools for MCP. Use these tools to work with external users in open lines',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			Lead\Activity\ToDo\CreateToDoFromOpenLine::class,
			Lead\Activity\Comment\CreateCommentFromOpenLine::class,

			Lead\UserField\ListUserFieldForOpenLine::class,
			Lead\UserField\AddUserFieldValueFromOpenLine::class,

			Deal\Activity\ToDo\CreateToDoFromOpenLine::class,
			Deal\Activity\Comment\CreateCommentFromOpenLine::class,

			Deal\UserField\ListUserFieldForOpenLine::class,
			Deal\UserField\AddUserFieldValueFromOpenLine::class,
		]);
	}
}
