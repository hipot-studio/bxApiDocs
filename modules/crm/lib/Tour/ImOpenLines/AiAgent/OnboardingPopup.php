<?php

namespace Bitrix\Crm\Tour\ImOpenLines\AiAgent;

use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Bizproc\WorkflowTemplateTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Tour\Base;
use Bitrix\ImOpenLines\Model\ConfigTable;
use Bitrix\ImOpenlines\Security\Permissions;
use Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class OnboardingPopup extends Base
{
	public const OPTION_NAME = 'imopenlines_ai_agent_onboarding_popup';
	public const AGENT_IS_RUN_OPTION_NAME = 'imopenlines_ai_agent_onboarding_agent_is_run';

	protected const CACHE_TTL = 6 * 3600;
	protected const CACHE_DIR = '/crm/Tour/ImOpenLines/';

	private ?int $templateId = 0;

	protected function canShow(): bool
	{
		return !$this->isUserSeenTour()
			&& $this->isFeatureAvailable()
			&& $this->hasActiveOpenLine()
			&& $this->getAiAgentTemplateId() !== null
			&& !$this->hasStartedAiAgents()
			&& $this->canModifyOpenLines()
		;
	}

	public function getComponentTemplate(): string
	{
		return 'imopenlines_ai_agent_onboarding_popup';
	}

	private function isFeatureAvailable(): bool
	{
		return Loader::includeModule('imopenlines')
			&& class_exists(AiOpenLinesOperatorAgentFeature::class)
			&& ServiceLocator::getInstance()->get(AiOpenLinesOperatorAgentFeature::class)?->isAvailable() === true
		;
	}

	private function hasActiveOpenLine(): bool
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return false;
		}

		if (!class_exists(ConfigTable::class))
		{
			return false;
		}

		$activeOpenLineCount = ConfigTable::query()
			->setSelect([
				'ID',
				'ACTIVE',
			])
			->where('ACTIVE', 'Y')
			->setCacheTtl(86400)
			->setLimit(1)
			->fetchObject()
		;

		return $activeOpenLineCount !== null;
	}

	protected function getOptions(): array
	{
		return [
			'AI_AGENT_TEMPLATE_ID' => $this->getAiAgentTemplateId(),
		];
	}

	private function getAiAgentTemplateId(): ?int
	{
		if ($this->isAgentRun())
		{
			$this->templateId = null;

			return $this->templateId;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return null;
		}

		if ($this->templateId !== 0)
		{
			return $this->templateId;
		}

		$templateId = WorkflowTemplateTable::query()
			->setSelect([
				'ID',
				'SYSTEM_CODE',
			])
			->where('SYSTEM_CODE', 'bitrix_ai_open_lines_operator')
			->setCacheTtl(86400)
			->setLimit(1)
			->fetchObject()
			?->getId()
		;

		$this->templateId = $templateId;

		return $this->templateId;
	}

	private function hasStartedAiAgents(): bool
	{
		if ($this->isAgentRun())
		{
			return true;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return false;
		}

		$templateSettings = WorkflowTemplateSettingsTable::query()
			->setSelect([
				'ID',
				'NAME',
				'VALUE',
			])
			->where('NAME', 'ORIGIN_SYSTEM_CODE')
			->where('VALUE', 'bitrix_ai_open_lines_operator')
			->setCacheTtl(86400)
			->setLimit(1)
			->fetchObject()
		;

		$hasActiveAiAgents = $templateSettings !== null;
		if ($hasActiveAiAgents)
		{
			$this->setIsAgentRun();

			return true;
		}

		return false;
	}

	private function canModifyOpenLines(): bool
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return false;
		}

		$userId = Container::getInstance()->getContext()->getUserId();

		$cache = Cache::createInstance();
		if ($cache->initCache(
			self::CACHE_TTL,
			'crm.tour.imopenlines.canModifyOpenLines.' . $userId,
			self::CACHE_DIR,
		))
		{
			$canModify = (bool)$cache->getVars();
		}
		else
		{
			$canModify = Permissions::createWithUserId($userId)
				->canPerform(Permissions::ENTITY_LINES, Permissions::ACTION_MODIFY)
			;

			$cache->startDataCache();
			$cache->endDataCache($canModify);
		}

		return $canModify;
	}

	private function isAgentRun(): bool
	{
		return Option::get(self::OPTION_CATEGORY, self::AGENT_IS_RUN_OPTION_NAME, 'N') === 'Y';
	}

	private function setIsAgentRun(): void
	{
		Option::set(self::OPTION_CATEGORY, self::AGENT_IS_RUN_OPTION_NAME, 'Y');
	}
}
