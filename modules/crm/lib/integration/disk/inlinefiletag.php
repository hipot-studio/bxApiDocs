<?php

namespace Bitrix\Crm\Integration\Disk;

final class InlineFileTag
{
	private string $nonce;

	public function __construct()
	{
		$this->nonce = bin2hex(random_bytes(8));
	}

	public function detachSizes(string $text): array
	{
		$sizesByOccurrence = [];
		$text = preg_replace_callback(
			'/\[DISK FILE ID\s*=\s*(n?\d+)((?:\s+(?:width|height)\s*=\s*\d+)+)\s*\]/i',
			function (array $matches) use (&$sizesByOccurrence): string {
				$occurrence = count($sizesByOccurrence);
				$sizes = [];
				preg_match_all('/(width|height)\s*=\s*(\d+)/i', $matches[2], $attributes, PREG_SET_ORDER);
				foreach ($attributes as $attribute)
				{
					$sizes[strtolower($attribute[1])] = (int)$attribute[2];
				}
				$sizesByOccurrence[$occurrence] = $sizes;

				return $this->getMarker($occurrence, 'open')
					. '[DISK FILE ID=' . $matches[1] . ']'
					. $this->getMarker($occurrence, 'close');
			},
			$text,
		);

		return [$text, $sizesByOccurrence];
	}

	public function restoreSizes(string $text, array $sizesByOccurrence): string
	{
		foreach ($sizesByOccurrence as $occurrence => $sizes)
		{
			$open = $this->getMarker($occurrence, 'open');
			$close = $this->getMarker($occurrence, 'close');
			$pattern = '/' . preg_quote($open, '/') . '\[DISK FILE ID\s*=\s*(n?\d+)\]'
				. preg_quote($close, '/') . '/i';
			$text = preg_replace_callback(
				$pattern,
				static function (array $matches) use ($sizes): string {
					$attributes = '';
					foreach (['width', 'height'] as $name)
					{
						if (isset($sizes[$name]))
						{
							$attributes .= ' ' . $name . '=' . $sizes[$name];
						}
					}

					return '[DISK FILE ID=' . $matches[1] . $attributes . ']';
				},
				$text,
			);
			$text = str_replace($open . $close, '', $text);
		}

		return preg_replace('/#crm-inline-size-' . $this->nonce . '-\d+-(?:open|close)#/', '', $text);
	}

	public static function extractId(string $reference): ?string
	{
		if (preg_match('/^\s*(n?\d+)(?:\s+(?:width|height)\s*=\s*\d+)*\s*$/iD', $reference, $matches) !== 1)
		{
			return null;
		}

		return $matches[1];
	}

	private function getMarker(int $occurrence, string $side): string
	{
		return '#crm-inline-size-' . $this->nonce . '-' . $occurrence . '-' . $side . '#';
	}
}
