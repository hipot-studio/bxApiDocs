<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\OpenLineDto;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\OpenLineItem;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\OpenLineItemCollection;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Builds {@see OpenLineDto} out of the internal chat projection. One direction only - the method
 * behind this contract reads.
 *
 * The requested form is a context of the whole page rather than a property of a chat, so it is
 * handed over here and written into every element.
 */
final class OpenLineDtoMapper
{
	/**
	 * A chat no active site widget shows has no preview at all, and its absence is part of the
	 * answer: the link is null instead of pointing at nothing.
	 *
	 * @param array<int, string> $previewUrlById preview links of the page, keyed by chat id
	 */
	public function getDtoCollection(
		int $formId,
		OpenLineItemCollection $openLines,
		array $previewUrlById,
	): DtoCollection
	{
		$collection = new DtoCollection(OpenLineDto::class);
		foreach ($openLines as $openLine)
		{
			$collection->add(self::createDto($formId, $openLine, $previewUrlById[$openLine->id] ?? null));
		}

		return $collection;
	}

	private static function createDto(
		int $formId,
		OpenLineItem $openLine,
		?string $previewUrl,
	): OpenLineDto
	{
		$dto = new OpenLineDto();
		$dto->formId = $formId;
		$dto->id = $openLine->id;
		$dto->name = $openLine->name;
		$dto->bound = $openLine->bound;
		$dto->welcomeFormEnabled = $openLine->welcomeFormEnabled;
		$dto->welcomeFormId = $openLine->welcomeFormId;
		$dto->welcomeFormDelayed = $openLine->welcomeFormDelayed;
		$dto->previewUrl = $previewUrl;

		return $dto;
	}
}
