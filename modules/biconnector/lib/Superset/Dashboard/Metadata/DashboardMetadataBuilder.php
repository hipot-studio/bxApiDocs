<?php

namespace Bitrix\BIConnector\Superset\Dashboard\Metadata;

use Bitrix\BIConnector\Superset\Dashboard\Metadata\MetadataSection\MetadataSectionInterface;

class DashboardMetadataBuilder
{
	/** @var array<MetadataSectionInterface> */
	private array $sections = [];

	public function addSection(MetadataSectionInterface $section): void
	{
		$this->sections[] = $section;
	}

	public function build(): array
	{
		$result = [];
		foreach ($this->sections as $section)
		{
			if ($section->isEmpty())
			{
				continue;
			}

			$result[$section->getSectionKey()] = $section->build();
		}

		return $result;
	}
}
