<?php

namespace Bitrix\AI\Engine\Cloud;

use Bitrix\AI\Engine\IContext;
use Bitrix\AI\Engine\IQueueOptional;
use Bitrix\AI\Engine\Trait\YandexARTCommonTrait;

class YandexART extends ImageCloudEngine implements IContext, IQueueOptional
{
	use YandexARTCommonTrait;

	protected const ENGINE_NAME = 'YandexART';
	public const ENGINE_CODE = 'YandexART';

	protected const URL_COMPLETIONS = 'https://ai.api.cloud.yandex.net/v1/images/generations';
	protected const URL_COMPLETIONS_QUEUE_PATH = '/api/v1/yandex/image/generation';

	protected const MODEL = 'art://<folder>/aliceai-image-art-3.0';

	protected const DEFAULT_FORMAT = 'square';

	protected const HTTP_STATUS_OK = 200;

	protected function getDefaultModel(): string
	{
		return 'yandex-art';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return self::ENGINE_NAME;
	}

	protected function makeRequestParams(array $postParams = []): array
	{
		if (empty($postParams))
		{
			$postParams = $this->preparePostParams();
			$postParams = array_merge($this->getParameters(), $postParams);
		}

		return $postParams;
	}

}
