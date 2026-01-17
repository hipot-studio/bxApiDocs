<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Command\Activity\Settings;

use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\Main\Result;

class SaveCommandHandlerResult extends Result
{
	public function setSettings(ActivityData $data): static
	{
		$this->data['settings'] = $data;

		return $this;
	}

	public function getSettings(): ?ActivityData
	{
		return $this->data['settings'] ?? null;
	}

	public function setVariables(array $variables): static
	{
		$this->data['variables'] = $variables;

		return $this;
	}

	public function getVariables(): array
	{
		return $this->data['variables'] ?? [];
	}

	public function setParameters(array $parameters): static
	{
		$this->data['parameters'] = $parameters;

		return $this;
	}

	public function getParameters(): array
	{
		return $this->data['parameters'] ?? [];
	}
}
