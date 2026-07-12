<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Filter;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Socialnetwork\Integration\Main\UIFilter\Workgroup;

abstract class AbstractPresetFilter
{
	private const PRESET_MESSAGE_MAP = [
		'active' => 'SONET_V2_FILTER_PRESET_ACTIVE',
		'my' => 'SONET_V2_FILTER_PRESET_MY',
		'favorites' => 'SONET_V2_FILTER_PRESET_FAVORITES',
		'extranet' => 'SONET_V2_FILTER_PRESET_EXTRANET',
		'archive' => 'SONET_V2_FILTER_PRESET_ARCHIVE',
	];

	private Options $filterOptions;

	public function __construct(
		protected readonly int $currentUserId,
		protected readonly string $idSuffix = '',
		protected readonly string $extranetSiteId = '',
		protected readonly string $customId = '',
	)
	{
		$this->filterOptions = new Options($this->getId(), $this->getPresets());
	}

	public function getId(): string
	{
		return ($this->customId !== '' ? $this->customId : static::FILTER_ID . $this->idSuffix);
	}

	public function getOptions(): Options
	{
		return $this->filterOptions;
	}

	public function getPresets(): array
	{
		$presets = $this->getLegacyPresets($this->buildPresetParams());

		return $this->localizePresetNames($presets);
	}

	protected function buildPresetParams(): array
	{
		return [
			'currentUserId' => $this->currentUserId,
			'contextUserId' => $this->getContextUserId(),
			'extranetSiteId' => $this->extranetSiteId,
			'mode' => $this->getMode(),
		];
	}

	protected function getLegacyPresets(array $params): array
	{
		return Workgroup::getFilterPresetList($params);
	}

	protected function getContextUserId(): int
	{
		return 0;
	}

	abstract protected function getMode(): string;

	protected function localizePresetNames(array $presets): array
	{
		foreach (self::PRESET_MESSAGE_MAP as $presetId => $messageCode)
		{
			if (!isset($presets[$presetId]) || !is_array($presets[$presetId]))
			{
				continue;
			}

			$presets[$presetId]['name'] = $this->getLocalizedPresetName(
				messageCode: $messageCode,
				fallback: (string)($presets[$presetId]['name'] ?? ''),
			);
		}

		return $presets;
	}

	protected function getLocalizedPresetName(string $messageCode, string $fallback): string
	{
		return Loc::getMessage($messageCode) ?? $fallback;
	}
}
