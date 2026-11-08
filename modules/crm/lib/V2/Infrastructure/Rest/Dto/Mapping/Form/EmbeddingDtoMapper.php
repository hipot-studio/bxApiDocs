<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding as Dto;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\Embedding;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingVariant;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\ViewOptions;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\ViewSettings;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

/**
 * Turns the internal embedding model into the published contract.
 *
 * The flat button block of the model becomes the nested `button` and `color` objects here, and the
 * parameters of a variant are written out one by one: a variant carries the settings and the
 * enumerations that belong to it, and nothing else.
 */
final class EmbeddingDtoMapper extends Mapper
{
	/**
	 * The method publishes no projection, so the whole model is mapped and $fields stays unused.
	 *
	 * @param Embedding[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(Dto\EmbeddingDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof Embedding)
			{
				throw new ArgumentTypeException('items', Embedding::class);
			}

			$collection->add($this->mapEmbedding($item));
		}

		return $collection;
	}

	private function mapEmbedding(Embedding $embedding): Dto\EmbeddingDto
	{
		$dto = new Dto\EmbeddingDto();
		$dto->formActive = $embedding->formActive;
		$dto->resourcesReady = $embedding->resourcesReady;
		$dto->publicUrl = self::requireLink($embedding->publicUrl, 'publicUrl');
		$dto->inline = $this->mapVariant($embedding->inline);
		$dto->click = $this->mapVariant($embedding->click);
		$dto->auto = $this->mapVariant($embedding->auto);

		return $dto;
	}

	private function mapVariant(EmbeddingVariant $variant): Dto\EmbeddingVariantDto
	{
		$dto = new Dto\EmbeddingVariantDto();
		$dto->code = $variant->code;
		$dto->previewUrl = self::requireLink($variant->previewUrl, 'previewUrl');
		$dto->settings = $variant->settings === null
			? null
			: $this->mapSettings($variant->settings, $variant->name)
		;
		$dto->options = $variant->options === null
			? null
			: $this->mapOptions($variant->options)
		;

		return $dto;
	}

	/**
	 * Which parameters a variant has is a property of the variant itself, so the name decides here
	 * the same way it decides in the storage the settings are read from.
	 */
	private function mapSettings(ViewSettings $settings, string $variant): Dto\ViewSettingsDto
	{
		$dto = new Dto\ViewSettingsDto();
		$dto->type = $settings->type;
		$dto->position = $settings->position;
		$dto->vertical = $settings->vertical;

		if ($variant === EmbeddingVariant::AUTO)
		{
			$dto->delay = $settings->delay;
		}

		if ($variant === EmbeddingVariant::CLICK)
		{
			$dto->button = $this->mapButton($settings);
		}

		return $dto;
	}

	private function mapButton(ViewSettings $settings): Dto\ButtonSettingsDto
	{
		$dto = new Dto\ButtonSettingsDto();
		$dto->use = $settings->buttonUsed;
		$dto->text = $settings->buttonText;
		$dto->font = $settings->buttonFont;
		$dto->align = $settings->buttonAlign;
		$dto->plain = $settings->buttonPlain;
		$dto->rounded = $settings->buttonRounded;
		$dto->outlined = $settings->buttonOutlined;
		$dto->decoration = $settings->buttonDecoration;
		$dto->color = $this->mapButtonColor($settings);

		return $dto;
	}

	private function mapButtonColor(ViewSettings $settings): Dto\ButtonColorSettingsDto
	{
		$dto = new Dto\ButtonColorSettingsDto();
		$dto->text = $settings->buttonTextColor;
		$dto->textHover = $settings->buttonTextHoverColor;
		$dto->background = $settings->buttonBackgroundColor;
		$dto->backgroundHover = $settings->buttonBackgroundHoverColor;

		return $dto;
	}

	/**
	 * An enumeration the model reports as absent belongs to another variant, so it is left out of
	 * the answer instead of coming back as an empty list.
	 */
	private function mapOptions(ViewOptions $options): Dto\ViewOptionsDto
	{
		$dto = new Dto\ViewOptionsDto();
		$dto->type = $options->types;
		$dto->position = $options->positions;
		$dto->vertical = $options->verticals;

		if ($options->delays !== null)
		{
			$dto->delay = $options->delays;
		}

		if ($options->buttonFonts !== null && $options->buttonAligns !== null)
		{
			$dto->button = $this->mapButtonOptions($options->buttonFonts, $options->buttonAligns);
		}

		return $dto;
	}

	/**
	 * @param string[] $fonts
	 * @param string[] $aligns
	 */
	private function mapButtonOptions(array $fonts, array $aligns): Dto\ButtonOptionsDto
	{
		$dto = new Dto\ButtonOptionsDto();
		$dto->font = $fonts;
		$dto->align = $aligns;

		return $dto;
	}

	/**
	 * Every link of the answer is built by the scenario, so a model that reached the mapper without
	 * one is an incomplete model rather than a form without a link.
	 */
	private static function requireLink(?string $link, string $name): string
	{
		if ($link === null)
		{
			throw new ArgumentNullException($name);
		}

		return $link;
	}
}
