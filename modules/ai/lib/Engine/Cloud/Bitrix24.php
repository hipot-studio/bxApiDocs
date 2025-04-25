<?php
declare(strict_types=1);

namespace Bitrix\AI\Engine\Cloud;

use Bitrix\AI\Engine;
use Bitrix\AI\Engine\IContext;
use Bitrix\AI\Engine\IQueueOptional;
use Bitrix\AI\Quality;
use phpDocumentor\Reflection\Types\Self_;

final class Bitrix24 extends CloudEngine implements IContext, IQueueOptional
{
	use Engine\Trait\BitrixGPTCommonTrait;

	protected const CATEGORY_CODE = Engine::CATEGORIES['text'];
	protected const ENGINE_NAME = 'BitrixGPT 4x';

	public const ENGINE_CODE = 'b24ai';

	protected const URL_COMPLETIONS = 'https://b24ai.bitrix.info/v1/chat/completions';

	protected const SYSTEM_ROLE = 'system';
	protected const DEFAULT_ROLE = 'user';

	protected const DEFAULT_MODEL = 'default';
	protected const TEMPERATURE = 0.12;

	protected const ABSENT_QUALITIES = [
		Quality::QUALITIES['give_advice'],
		Quality::QUALITIES['meeting_processing'],
		Quality::QUALITIES['ai_site'],
	];

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
		return !array_intersect($quality->getRequired(), self::ABSENT_QUALITIES);
	}

	protected function availableForModules(): array
	{
		return [];
	}
}
