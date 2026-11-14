<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Normalizes the list of extensions sent by an administrator before it is saved as a policy.
 *
 * An item that does not fit the format is never repaired beyond case, spaces and leading dots:
 * it is reported back instead.
 */
class ExtensionListNormalizer
{
	public const MAX_COUNT = 500;
	public const ERROR_INVALID_EXTENSIONS = 'INVALID_EXTENSIONS';
	public const ERROR_POLICY_TOO_LARGE = 'POLICY_TOO_LARGE';

	protected const EXTENSION_PATTERN = '/^[a-z0-9]{1,16}$/';

	/**
	 * Returns the normalized list of extensions in the "extensions" key of the result data,
	 * or the errors of the check.
	 *
	 * @param array $rawList
	 * @return Result
	 */
	public function normalize(array $rawList): Result
	{
		$result = new Result();
		$extensions = [];
		$invalidExtensions = [];

		foreach ($rawList as $position => $rawExtension)
		{
			if (!is_scalar($rawExtension))
			{
				$rejected = self::describeRejectedItem($position, $rawExtension);
				$invalidExtensions[$rejected] = $rejected;

				continue;
			}

			$extension = ltrim(strtolower(trim((string)$rawExtension)), '.');
			if ($extension === '')
			{
				continue;
			}

			if (preg_match(self::EXTENSION_PATTERN, $extension))
			{
				$extensions[$extension] = $extension;
			}
			else
			{
				$invalidExtensions[$extension] = $extension;
			}
		}

		if (!empty($invalidExtensions))
		{
			$result->addError(new Error(
				'The list contains invalid extensions.',
				self::ERROR_INVALID_EXTENSIONS,
				['extensions' => array_values($invalidExtensions)],
			));
		}

		if (count($extensions) > self::MAX_COUNT)
		{
			$result->addError(new Error(
				'The list of extensions is larger than ' . self::MAX_COUNT . ' items.',
				self::ERROR_POLICY_TOO_LARGE,
			));
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		return $result->setData([
			'extensions' => array_values($extensions),
		]);
	}

	/**
	 * An item that is no value at all - a nested array, nothing - has no form to be shown back in.
	 * What is reported instead is where it stood in the list that was sent and what it was: the
	 * item itself never travels back, and the administrator still finds the place it came from.
	 *
	 * The same wording serves every list of the settings, so a rejected item reads the same way
	 * wherever it is reported from.
	 *
	 * @param int|string $position Key the item had in the list that was sent.
	 * @param mixed $value Item itself; only its type is taken.
	 * @return string
	 */
	public static function describeRejectedItem(int|string $position, mixed $value): string
	{
		return '[' . $position . ']: ' . get_debug_type($value);
	}
}
