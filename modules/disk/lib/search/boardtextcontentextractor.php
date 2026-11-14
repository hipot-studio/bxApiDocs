<?php

declare(strict_types=1);

namespace Bitrix\Disk\Search;

final class BoardTextContentExtractor
{
	/**
	 * @param array $items Decoded BoardElementsResult items with stdClass objects.
	 * @return list<string>
	 */
	public function extract(array $items): array
	{
		$fragments = [];
		foreach ($items as $item)
		{
			if (!$item instanceof \stdClass || !($item->properties ?? null) instanceof \stdClass)
			{
				continue;
			}

			foreach ($this->getTextValues($item) as $value)
			{
				if (!is_string($value))
				{
					continue;
				}

				$value = str_replace(["\r\n", "\r"], "\n", $value);
				$value = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $value));
				if ($value !== '')
				{
					$fragments[] = $value;
				}
			}
		}

		return $fragments;
	}

	private function getTextValues(\stdClass $item): array
	{
		$type = $item->type ?? null;
		$properties = $item->properties;
		if ($type === 'IMAGE')
		{
			return [$properties->imageName ?? null];
		}

		if (!in_array($type, [
			'FRAME',
			'RECTANGLE',
			'CIRCLE',
			'DIAMOND',
			'TRIANGLE',
			'STICKER',
			'TEXT',
			'CODE_BLOCK',
			'SHAPE',
		], true))
		{
			return [];
		}

		$values = [];
		if (($properties->text ?? null) instanceof \stdClass)
		{
			$values[] = $properties->text->value ?? null;
		}

		if (
			$type === 'SHAPE'
			&& ($properties->shape ?? null) instanceof \stdClass
			&& is_array($properties->shape->additionalTexts ?? null)
		)
		{
			foreach ($properties->shape->additionalTexts as $additionalText)
			{
				if ($additionalText instanceof \stdClass)
				{
					$values[] = $additionalText->value ?? null;
				}
			}
		}

		return $values;
	}
}
