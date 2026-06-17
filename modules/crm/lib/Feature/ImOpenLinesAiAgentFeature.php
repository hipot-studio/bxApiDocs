<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Integration;
use Bitrix\Crm\Settings\Crm;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use ReflectionEnum;
use RuntimeException;

final class ImOpenLinesAiAgentFeature extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_IMOL_AI_AGENT_FEATURE_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Integration::getInstance();
	}

	public function isEnabled(): bool
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return false;
		}

		if (!class_exists(\Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature::class))
		{
			return false;
		}

		return ServiceLocator::getInstance()->get(\Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature::class)?->isAvailable();
	}

	public function enable(): void
	{
		$modules = [
			'imopenlines',
			'aiassistant',
			'bizproc',
			'imbot',
			'crm',
			'ui',
			'rag',
		];

		foreach ($modules as $module)
		{
			if (!Loader::includeModule($module))
			{
				throw new RuntimeException("Module $module not installed");
			}
		}

		if (!class_exists(\Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature::class))
		{
			throw new RuntimeException('Incorrect imopenlines module version');
		}

		if (!class_exists(\Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ConstantConfiguration::class))
		{
			throw new RuntimeException("Incorrect bizproc module version");
		}

		if (!class_exists(\Bitrix\ImBot\Bot\OpenLinesBizprocBot::class))
		{
			throw new RuntimeException("Incorrect imbot module version");
		}

		\CBPRuntime::GetRuntime()->includeActivityFile('CBPAiAssistantAgentActivity');
		if (!method_exists(\CBPAiAssistantAgentActivity::class, 'collectCustomContext'))
		{
			throw new RuntimeException("Incorrect aiassistant module version");
		}

		$outlineEnumReflection = new ReflectionEnum(\Bitrix\Ui\Public\Enum\IconSet\Outline::class);
		if (!$outlineEnumReflection->hasCase('OPEN_CHANNELS_CROSS'))
		{
			throw new RuntimeException("Incorrect ui module version");
		}

		if (!class_exists(\Bitrix\Rag\Public\Service\KnowledgeBasePublicService::class))
		{
			throw new RuntimeException('Incorrect rag module version');
		}

		/** @see \Bitrix\Bizproc\Public\Integration\AiAssistant\EventHandler\AiAssistantAgentActivity::onCollectCustomContext() */
		\Bitrix\Main\EventManager::getInstance()
			->registerEventHandler(
				'aiassistant',
				'AiAssistantAgentActivity::onCollectCustomContext',
				'bizproc',
				\Bitrix\Bizproc\Public\Integration\AiAssistant\EventHandler\AiAssistantAgentActivity::class,
				'onCollectCustomContext',
			);

		/** @see \Bitrix\ImOpenLines\V2\Integration\AiAssistant\EventHandler\AiAssistantAgentActivity::onCollectCustomContext */
		\Bitrix\Main\EventManager::getInstance()
			->registerEventHandler(
				'aiassistant',
				'AiAssistantAgentActivity::onCollectCustomContext',
				'imopenlines',
				\Bitrix\ImOpenLines\V2\Integration\AiAssistant\EventHandler\AiAssistantAgentActivity::class,
				'onCollectCustomContext',
			);

		/** @see \Bitrix\ImOpenLines\V2\Integration\Bizproc\EventHandler\SetupTemplateActivity::onProvideSelectors */
		\Bitrix\Main\EventManager::getInstance()
			->registerEventHandler(
				'bizproc',
				'SetupTemplateActivitySelectorProvider::onProvideSelectors',
				'imopenlines',
				\Bitrix\ImOpenLines\V2\Integration\Bizproc\EventHandler\SetupTemplateActivity::class,
				'onProvideSelectors',
			);

		Option::set('bizproc', 'designer_v2', 'Y');
		Option::set('bizproc', 'feature_ai_agents', 'Y');
		Option::set('bizproc', 'complex_activity_excluded', 0);
		Option::set('bizproc', 'is_rag_available', "Y");
		Option::set('aiassistant', 'node_enabled', 'Y');

		ServiceLocator::getInstance()->get(\Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature::class)?->enableFeature();
		$this->logEnabled();
	}

	public function disable(): void
	{
		if (!Loader::includeModule('imopenlines'))
		{
			throw new RuntimeException('Module imopenlines not installed');
		}

		if (!class_exists(\Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature::class))
		{
			throw new RuntimeException('Incorrect imopenlines module version');
		}

		ServiceLocator::getInstance()->get(\Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature::class)?->disableFeature();
		$this->logDisabled();
	}
}
