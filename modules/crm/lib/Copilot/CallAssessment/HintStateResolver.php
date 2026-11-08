<?php

namespace Bitrix\Crm\Copilot\CallAssessment;

final class HintStateResolver
{
	public function resolve(?bool $rawState, string $prompt, ?string $code): bool
	{
		if ($rawState !== null)
		{
			return $rawState;
		}

		if (trim($prompt) === '')
		{
			return false;
		}

		if (trim((string)$code) === '')
		{
			return true;
		}

		return !$this->isShippedReference($prompt, (string)$code);
	}

	public function hasHint(?bool $rawState, string $prompt, ?string $code): bool
	{
		return trim($prompt) !== '' && $this->resolve($rawState, $prompt, $code);
	}

	public function isShippedReference(string $prompt, string $code): bool
	{
		$prompt = trim($prompt);
		foreach (FillPreliminaryCallAssessments::getReferencePromptsByCode($code) as $reference)
		{
			if ($prompt === trim($reference))
			{
				return true;
			}
		}

		return false;
	}
}
