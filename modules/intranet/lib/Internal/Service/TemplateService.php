<?php

namespace Bitrix\Intranet\Internal\Service;

use Bitrix\Intranet\Internal\Enum\TemplateSeason;

class TemplateService
{
	public function getCurrentSeasonData(): array
	{
		$currentSeason = TemplateSeason::fromCurrentDate();
		$currentSeasonZone = TemplateSeason::getSeasonZone($currentSeason);

		return [
			'cssClassName' => $this->getCssClassName($currentSeason->value, $currentSeasonZone),
			'urlSubCut' => $this->getSubCat($currentSeason->value, $currentSeasonZone),
		];
	}

	private function getSubCat(String $seasonName, ?String $seasonZone): string
	{
		$subCat = $seasonName;

		if ($seasonZone)
		{
			$subCat .= '/' . $seasonZone;
		}

		return $subCat;
	}

	private function getCssClassName(String $seasonName, ?String $seasonZone): string
	{
		$suffix = $seasonName;

		if ($seasonZone)
		{
			$suffix .= '-' . $seasonZone;
		}

		return "intranet-bg intranet-bg--{$suffix}";
	}
}
