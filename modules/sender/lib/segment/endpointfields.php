<?php

namespace Bitrix\Sender\Segment;

/**
 * Fields of a segment source without what the form adds to them: captions of the values,
 * its service field, empty values and numbers as strings.
 */
final class EndpointFields
{
	private const AUX_FIELD = 'bx_aux_hidden_field';
	private const LABEL_SUFFIX = '_label';

	/**
	 * False and non-scalars are kept as they are: the connector may read them as a value.
	 */
	public static function normalize(mixed $fields): array
	{
		if (!is_array($fields))
		{
			return [];
		}

		$normalized = [];
		foreach ($fields as $key => $value)
		{
			if ($key === self::AUX_FIELD || str_ends_with((string)$key, self::LABEL_SUFFIX))
			{
				continue;
			}

			if (is_array($value))
			{
				$value = self::normalize($value);
				if ($value === [])
				{
					continue;
				}
			}
			elseif ($value === null || $value === '')
			{
				continue;
			}
			elseif (is_scalar($value) && $value !== false)
			{
				$value = (string)$value;
			}

			$normalized[$key] = $value;
		}

		ksort($normalized);

		return $normalized;
	}
}
