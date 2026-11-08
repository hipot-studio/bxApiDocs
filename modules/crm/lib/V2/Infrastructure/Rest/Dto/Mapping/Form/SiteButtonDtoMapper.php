<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\SiteButtonDto;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\SiteButtonItem;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\SiteButtonItemCollection;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Builds {@see SiteButtonDto} out of the internal widget projection. One direction only - the method
 * behind this contract reads.
 *
 * The requested form is a context of the whole page rather than a property of a widget, so it is
 * handed over here and written into every element; the internal model deliberately carries no form
 * of its own.
 */
final class SiteButtonDtoMapper
{
	/**
	 * @param array<int, string> $previewUrlById preview links of the page, keyed by widget id
	 */
	public function getDtoCollection(
		int $formId,
		SiteButtonItemCollection $siteButtons,
		array $previewUrlById,
	): DtoCollection
	{
		$collection = new DtoCollection(SiteButtonDto::class);
		foreach ($siteButtons as $siteButton)
		{
			$collection->add(self::createDto($formId, $siteButton, $previewUrlById[$siteButton->id] ?? null));
		}

		return $collection;
	}

	/**
	 * Every link of the answer is built by the scenario, so a widget that reached the mapper without
	 * one is an incomplete projection rather than a widget without a preview.
	 */
	private static function createDto(
		int $formId,
		SiteButtonItem $siteButton,
		?string $previewUrl,
	): SiteButtonDto
	{
		if ($previewUrl === null)
		{
			throw new ArgumentNullException('previewUrl');
		}

		$dto = new SiteButtonDto();
		$dto->formId = $formId;
		$dto->id = $siteButton->id;
		$dto->name = $siteButton->name;
		$dto->system = $siteButton->system;
		$dto->bound = $siteButton->bound;
		$dto->formsId = $siteButton->formIds;
		$dto->previewUrl = $previewUrl;

		return $dto;
	}
}
