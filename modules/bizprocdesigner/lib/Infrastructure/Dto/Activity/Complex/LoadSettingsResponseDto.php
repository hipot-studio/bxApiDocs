<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use JsonSerializable;

class LoadSettingsResponseDto implements JsonSerializable
{
	/**
	 *
	 * @param string $title
	 * @param string $description
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 * @param array<string, ActionDictionaryEntryDto> $actionEntryDtoDictionary
	 * @param array|null $fixedDocumentType
	 */
	public function __construct(
		public string $title,
		public string $description,
		public array $portRuleDtoDictionary,
		public array $actionEntryDtoDictionary,
		public ?array $fixedDocumentType = null,
	)
	{

	}

	public function jsonSerialize(): array
	{
		return [
			'title' => $this->title,
			'description' => $this->description,
			'rules' => $this->portRuleDtoDictionary,
			'actions' => $this->actionEntryDtoDictionary,
			'fixedDocumentType' => $this->fixedDocumentType,
		];
	}
}
