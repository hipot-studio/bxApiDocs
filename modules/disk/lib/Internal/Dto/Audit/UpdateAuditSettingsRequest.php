<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use Bitrix\Main\Request;

final class UpdateAuditSettingsRequest
{
	public function __construct(
		public readonly ?bool $enabled,
		public readonly ?string $retentionPeriod,
		public readonly ?bool $confirmRetentionDecrease,
	)
	{
	}

	public static function createFromRequest(Request $request): self
	{
		$data = [
			'enabled' => $request->getPost('enabled'),
			'retentionPeriod' => $request->getPost('retentionPeriod'),
		];
		if ($request->getPost('confirmRetentionDecrease') !== null)
		{
			$data['confirmRetentionDecrease'] = $request->getPost('confirmRetentionDecrease');
		}

		return self::createFromArray($data);
	}

	public static function createFromArray(array $data): self
	{
		return new self(
			enabled: array_key_exists('enabled', $data) ? self::normalizeBoolean($data['enabled']) : null,
			retentionPeriod: is_string($data['retentionPeriod'] ?? null) ? $data['retentionPeriod'] : null,
			confirmRetentionDecrease: array_key_exists('confirmRetentionDecrease', $data)
				? self::normalizeConfirmation($data['confirmRetentionDecrease'])
				: false,
		);
	}

	private static function normalizeConfirmation(mixed $value): ?bool
	{
		return match ($value) {
			true, 'true' => true,
			false, 'false' => false,
			default => null,
		};
	}

	private static function normalizeBoolean(mixed $value): ?bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		if (is_int($value))
		{
			return match ($value) {
				0 => false,
				1 => true,
				default => null,
			};
		}

		if (!is_string($value))
		{
			return null;
		}

		$value = mb_strtolower(trim($value));

		return match ($value) {
			'0', 'n', 'no', 'false', 'off' => false,
			'1', 'y', 'yes', 'true', 'on' => true,
			default => null,
		};
	}
}
