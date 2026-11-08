<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Bizproc\Workflow\Template\AgentOwnedMetadata;

/** Validated submitted metadata; null means that the field was absent. */
final readonly class AgentTemplateMetadata
{
	public function __construct(
		public ?string $name = null,
		public ?string $description = null,
	) {}

	/** @return array<string, string> */
	public function toTemplateFields(): array
	{
		$fields = [];
		if ($this->name !== null)
		{
			$fields[AgentOwnedMetadata::FIELD_NAME] = $this->name;
		}
		if ($this->description !== null)
		{
			$fields[AgentOwnedMetadata::FIELD_DESCRIPTION] = $this->description;
		}

		return $fields;
	}

	/** @return list<string> */
	public function claimedFields(): array
	{
		return array_keys($this->toTemplateFields());
	}
}
