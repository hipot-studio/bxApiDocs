<?php

namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Call\Integration\AI\MentionService;

abstract class AISenseContent
{
	protected int $version = 1;

	protected int $callId;

	protected ?MentionService $mentionService = null;

	abstract public function toRestFormat(string $mentionFormat = 'bb'): array;

	public function getVersion(): int
	{
		return $this->version;
	}

	/**
	 * Convert array structure to stdClass recursively
	 * @param array $input
	 * @return \stdClass
	 */
	protected function convertObjectStructure(array $input): \stdClass
	{
		$output = new \stdClass();
		foreach ($input as $key => $val)
		{
			if (is_array($val) && !empty($val))
			{
				$val = $this->convertObjectStructure($val);
			}
			if (!is_null($val))
			{
				$key = $this->generateFieldKey($key);
				$output->{$key} = $val;
			}
		}
		return $output;
	}

	protected function generateFieldKey(string $key): string
	{
		return lcfirst(str_replace('_', '', ucwords($key, '_')));
	}

	protected function getMentionService(): MentionService
	{
		if (!$this->mentionService)
		{
			$this->mentionService = MentionService::getInstance();
			$this->mentionService->loadMentionsForCall($this->callId);
		}

		return $this->mentionService;
	}
}
