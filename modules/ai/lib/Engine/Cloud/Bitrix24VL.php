<?php
declare(strict_types=1);

namespace Bitrix\AI\Engine\Cloud;

use Bitrix\AI\Engine;
use Bitrix\AI\Engine\IContext;
use Bitrix\AI\Engine\IQueueOptional;
use Bitrix\AI\Facade\AiUrlManager;
use Bitrix\AI\Quality;
use Bitrix\Main\DI\ServiceLocator;

final class Bitrix24VL extends CloudEngine implements IContext, IQueueOptional
{
	use Engine\Trait\BitrixGPTVLCommonTrait;

	protected const CATEGORY_CODE = Engine::CATEGORIES['vision'];
	protected const ENGINE_NAME = 'BitrixGPT 5 VL';

	public const ENGINE_CODE = 'b24ai-vl';

	protected const SYSTEM_ROLE = 'system';
	protected const DEFAULT_ROLE = 'user';

	protected const DEFAULT_MODEL = 'bitrixgpt-5-vl';
	protected const TEMPERATURE = 0.2;
	protected const MAX_TOKENS = 8000;
	protected const REQUIRES_PERSONAL_DATA_OBFUSCATION = false;

	protected int $modelContextLimit = 15745;

	public function getName(): string
	{
		return self::ENGINE_NAME;
	}

	protected function getDefaultModel(): string
	{
		return self::DEFAULT_MODEL;
	}

	public function hasQuality(Quality $quality): bool
	{
		return false;
	}

	protected function availableForModules(): array
	{
		return [];
	}

	protected function getCompletionsUrl(): string
	{
		return $this->getAiUrlManager()->getChatCompletionsUrl();
	}

	protected function getAiUrlManager(): AiUrlManager
	{
		return ServiceLocator::getInstance()->get(AiUrlManager::class);
	}
}
