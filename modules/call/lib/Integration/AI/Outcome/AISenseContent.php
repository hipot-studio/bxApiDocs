<?php

namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Call\Integration\AI\MentionService;

abstract class AISenseContent
{
	protected int $version = 1;

	protected int $callId;

	protected ?MentionService $mentionService = null;

	protected bool $isEmpty = true;

	abstract public function toRestFormat(string $mentionFormat = MentionService::FORMAT_BB): array;

	public function getVersion(): int
	{
		return $this->version;
	}

	public function hasContent(bool $ignoreComputedCriteria = false): bool
	{
		return !$this->isEmpty;
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

	/**
	 * Render @-mentions inside a value per the requested format. Single source for the
	 * bb/html/none dispatch shared by every toRestFormat() — keeps the mapping from
	 * drifting per subclass.
	 */
	protected function applyMentionFormat(string $value, string $mentionFormat): string
	{
		return match ($mentionFormat)
		{
			MentionService::FORMAT_HTML => $this->getMentionService()->replaceBBMentions($value),
			MentionService::FORMAT_NONE => $this->getMentionService()->removeBBMentions($value),
			default => $value, // bb — keep raw [USER=…]…[/USER]
		};
	}
}
