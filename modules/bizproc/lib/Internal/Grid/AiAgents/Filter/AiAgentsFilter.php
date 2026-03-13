<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Filter;

use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\UI\Filter\Options;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\Presets\FilterPresetManager;

class AiAgentsFilter extends Filter
{
	private Options $filterOptions;
	private ?AiAgentsFilterSettings $filterSettings = null;
	protected $uiFilterServiceFields = [
		'LAUNCHED_BY',
	];

	public function __construct(
		string $ID,
		DataProvider $entityDataProvider,
		?array $extraDataProviders = null,
		?array $params = null,
		array $additionalPresets = [],
	) {
		parent::__construct($ID, $entityDataProvider, $extraDataProviders, $params);

		if (isset($params['FILTER_SETTINGS']) && $params['FILTER_SETTINGS'] instanceof AiAgentsFilterSettings)
		{
			$this->filterSettings = $params['FILTER_SETTINGS'];
		}

		$presetManager = new FilterPresetManager($this->filterSettings, $additionalPresets);

		$this->filterOptions = new Options(
			$this->getId(),
			$presetManager->getPresetsArrayData(),
		);

		$sessionFilterId = $this->filterOptions->getSessionFilterId();
		foreach ($presetManager->getPresets() as $preset)
		{
			$isCurrentPreset = $preset->getId() === $sessionFilterId;

			$this->filterOptions->setFilterSettings(
				$preset->getId(),
				$preset->toArray(),
				$isCurrentPreset,
			);
		}

		foreach ($presetManager->getDisabledPresets() as $preset)
		{
			$this->filterOptions->deleteFilter($preset->getId(), $preset->isDefault());
		}

		$this->filterOptions->save();
	}

	public function getFilterSettings(): ?AiAgentsFilterSettings
	{
		return $this->filterSettings;
	}
}
