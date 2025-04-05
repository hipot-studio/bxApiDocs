<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Cloud;

use Bitrix\AI\Engine;
use Bitrix\AI\Engine\IQueueOptional;
use Bitrix\AI\Quality;
use Bitrix\AI\Result;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

final class AudioCall extends CloudEngine implements IQueueOptional
{
	use Engine\Trait\AudioCommonTrait;

	protected const CATEGORY_CODE = Engine::CATEGORIES['call'];
	protected const ENGINE_NAME = 'AudioCall';
	public const ENGINE_CODE = 'AudioCall';
	protected const URL_COMPLETIONS = 'https://b24ai.bitrix.info/v1/call/transcriptions';

	public function getName(): string
	{
		return self::ENGINE_NAME;
	}

	protected function getDefaultModel(): string
	{
		return 'default';
	}

	public function isAvailable(): bool
	{
		return Option::get('ai', 'audio_call_enabled', 'N') === 'Y';
	}

	public function getResultFromRaw(mixed $rawResult, bool $cached = false): Result
	{
		$jsonData = null;
		if (\is_array($rawResult))
		{
			$jsonData = $rawResult;
		}

		return new Result($rawResult, Json::encode($rawResult), $cached, $jsonData);
	}

}
