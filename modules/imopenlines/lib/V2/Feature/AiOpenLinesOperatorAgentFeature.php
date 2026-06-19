<?php

namespace Bitrix\ImOpenLines\V2\Feature;

use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ConstantConfiguration;
use Bitrix\Bizproc\Internal\Integration\Rag\Service\RagService;
use Bitrix\Crm\Integration\AiAssistant\ToolSets\OpenLineToolSet;
use Bitrix\ImBot\Bot\OpenLinesBizprocBot;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Enum\IconSet\Outline;
use CBPAiAssistantAgentActivity;

final class AiOpenLinesOperatorAgentFeature
{
	private const OPTION_NAME = 'ai_agent_open_lines_operator_is_enabled';
	private const MODULE_ID = 'imopenlines';

	public function isAvailable(): bool
	{
		if (!$this->isFeatureEnabled())
		{
			return false;
		}

		if (
			!Loader::includeModule('aiassistant')
			|| !Loader::includeModule('bizproc')
			|| !Loader::includeModule('imbot')
			|| !Loader::includeModule('crm')
			|| !Loader::includeModule('ui')
		)
		{
			return false;
		}

		\CBPRuntime::GetRuntime()->includeActivityFile('CBPAiAssistantAgentActivity');
		$outlineEnumReflection = new \ReflectionEnum(Outline::class);
		$ragService = class_exists(RagService::class) ? ServiceLocator::getInstance()->get(RagService::class) : null;

		return (
			class_exists(ConstantConfiguration::class)
			&& class_exists(OpenLinesBizprocBot::class)
			&& class_exists(OpenLineToolSet::class)
			&& method_exists(CBPAiAssistantAgentActivity::class, 'collectCustomContext')
			&& $outlineEnumReflection->hasCase('OPEN_CHANNELS_CROSS')
			&& ($ragService?->isAvailable() ?? false)
		);
	}

	public function isFeatureEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, 'N') === 'Y';
	}

	public function enableFeature(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, 'Y');
	}

	public function disableFeature(): void
	{
		Option::delete(self::MODULE_ID, [
			'name' => self::OPTION_NAME,
		]);
	}
}
