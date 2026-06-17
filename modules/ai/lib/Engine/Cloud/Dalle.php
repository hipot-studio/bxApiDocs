<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Cloud;

use Bitrix\AI\Engine;
use Bitrix\AI\Engine\IContext;
use Bitrix\AI\Engine\IQueueOptional;
use Bitrix\Main\Application;

final class Dalle extends ImageCloudEngine  implements IContext, IQueueOptional
{
	use Engine\Trait\DalleCommonTrait;

	protected const ENGINE_NAME = 'gpt-image-1';
	public const ENGINE_CODE = 'Dalle';

	protected const URL_COMPLETIONS = 'https://api.openai.com/v1/images/generations';

	protected const DEFAULT_MODEL = 'gpt-image-1';
	protected const DEFAULT_QUALITY = 'medium';

	protected function getDefaultModel(): string
	{
		return self::DEFAULT_MODEL;
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return static::ENGINE_NAME;
	}

	/**
	 * Check if engine is available for current region.
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		return !in_array($region, ['ru', 'by']);
	}
}
