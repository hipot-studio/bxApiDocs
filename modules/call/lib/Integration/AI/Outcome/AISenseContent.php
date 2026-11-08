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

	/**
	 * Loads the mention dictionary of this call: needed only where a mention has to be matched
	 * against the participants of the call (AI mentions, speaker detection). Rendering BB mentions
	 * does not go through here — see {@see getMentionRenderer()}.
	 */
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
	 * Rendering resolves mentions by the user id inside the tag, so it needs no dictionary and
	 * never loads the call. Batch reads over many calls depend on that.
	 */
	private function getMentionRenderer(): MentionService
	{
		return $this->mentionService ?? MentionService::getInstance();
	}

	/**
	 * Render @-mentions inside a value per the requested format. Single source for the
	 * bb/html/none dispatch shared by every toRestFormat() — keeps the mapping from
	 * drifting per subclass.
	 */
	protected function applyMentionFormat(string $value, string $mentionFormat): string
	{
		if (!$this->hasBBMentions($value))
		{
			return $value;
		}

		return match ($mentionFormat)
		{
			MentionService::FORMAT_HTML => (string)$this->getMentionRenderer()->replaceBBMentions($value),
			MentionService::FORMAT_NONE => (string)$this->getMentionRenderer()->removeBBMentions($value),
			default => $value, // bb — keep raw [USER=…]…[/USER]
		};
	}

	protected function removeMentions(string $value): string
	{
		return $this->hasBBMentions($value)
			? (string)$this->getMentionRenderer()->removeBBMentions($value)
			: $value
		;
	}

	protected function hasBBMentions(string $value): bool
	{
		return stripos($value, '[user=') !== false;
	}
}
