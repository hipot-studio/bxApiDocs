<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Command\Activity\Settings;

use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\Main\Result;

class SaveCommandResult extends Result
{
	public function setSettings(ActivityData $settings): static
	{
		$this->data['settings'] = $settings;

		return $this;
	}

	public function setVariables(array $variables): static
	{
		$this->data['variables'] = $variables;

		return $this;
	}

	public function setParameters(array $parameters): static
	{
		$this->data['parameters'] = $parameters;

		return $this;
	}

	public function getSettings(): ?ActivityData
	{
		return $this->data['settings'] ?? null;
	}

	public function getVariables(): array
	{
		return $this->data['variables'] ?? [];
	}

	public function getParameters(): array
	{
		return $this->data['parameters'] ?? [];
	}
}
