<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

final class AudienceCodeSet
{
	public const CODE_AUTHORIZED = 'AU';

	private const PATTERN = '/^(U|D|DR)([1-9][0-9]*)$/D';

	/**
	 * @param int[] $userIds
	 * @param string[] $departmentCodes
	 * @param string[] $recursiveDepartmentCodes
	 * @param string[] $unsupported
	 */
	private function __construct(
		public readonly array $userIds,
		public readonly bool $everyEmployee,
		public readonly array $departmentCodes,
		public readonly array $recursiveDepartmentCodes,
		public readonly array $unsupported,
	) {}

	public static function isSupported(string $code): bool
	{
		return $code === self::CODE_AUTHORIZED || preg_match(self::PATTERN, $code) === 1;
	}

	/**
	 * @param string[] $codes
	 */
	public static function fromCodes(array $codes): self
	{
		$userIds = [];
		$everyEmployee = false;
		$departments = [];
		$recursiveDepartments = [];
		$unsupported = [];

		foreach (array_unique(array_filter($codes, 'is_string')) as $code)
		{
			if (trim($code) === '')
			{
				continue;
			}
			if ($code === self::CODE_AUTHORIZED)
			{
				$everyEmployee = true;

				continue;
			}
			if (preg_match(self::PATTERN, $code, $matches) !== 1)
			{
				$unsupported[$code] = true;

				continue;
			}

			$prefix = $matches[1];
			$number = (int)$matches[2];

			match ($prefix)
			{
				'U' => $userIds[$number] = true,
				'DR' => $recursiveDepartments['D' . $number] = $departments['D' . $number] = true,
				'D' => $departments['D' . $number] = true,
			};
		}

		return new self(
			array_map('intval', array_keys($userIds)),
			$everyEmployee,
			array_keys($departments),
			array_keys($recursiveDepartments),
			array_keys($unsupported),
		);
	}

	public function namesNoStructure(): bool
	{
		return $this->departmentCodes === [];
	}
}
