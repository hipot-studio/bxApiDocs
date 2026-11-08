<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Dto;

/**
 * State of the report screen as the server sees it. The mobile screen is a projection of this value:
 * it mounts the Superset frame only for STATE_READY, waits on STATE_PREPARING and shows the error
 * screen on STATE_ERROR. Nothing about the report lifecycle is decided on the client.
 */
final class DashboardViewState
{
	public const STATE_READY = 'ready';
	public const STATE_PREPARING = 'preparing';
	public const STATE_ERROR = 'error';

	public const REASON_NO_ACCESS = 'no_access';
	public const REASON_SUPERSET_UNAVAILABLE = 'superset_unavailable';

	private function __construct(
		public readonly string $state,
		public readonly ?string $reason,
		public readonly string $dashboardStatus,
		public readonly string $dashboardType,
		public readonly string $supersetStatus,
		public readonly bool $needsInstall,
		public readonly bool $isFirstStartup,
	)
	{
	}

	public static function error(
		string $reason,
		string $supersetStatus,
		string $dashboardStatus = '',
		string $dashboardType = '',
	): self
	{
		return new self(
			self::STATE_ERROR,
			$reason,
			$dashboardStatus,
			$dashboardType,
			$supersetStatus,
			needsInstall: false,
			isFirstStartup: false,
		);
	}

	public static function preparing(
		string $supersetStatus,
		string $dashboardStatus,
		string $dashboardType,
		bool $needsInstall,
		bool $isFirstStartup,
	): self
	{
		return new self(
			self::STATE_PREPARING,
			null,
			$dashboardStatus,
			$dashboardType,
			$supersetStatus,
			$needsInstall,
			$isFirstStartup,
		);
	}

	public static function ready(
		string $supersetStatus,
		string $dashboardStatus,
		string $dashboardType,
	): self
	{
		return new self(
			self::STATE_READY,
			null,
			$dashboardStatus,
			$dashboardType,
			$supersetStatus,
			needsInstall: false,
			isFirstStartup: false,
		);
	}

	public function isPreparing(): bool
	{
		return $this->state === self::STATE_PREPARING;
	}

	public function toArray(): array
	{
		return [
			'state' => $this->state,
			'reason' => $this->reason,
			'dashboardStatus' => $this->dashboardStatus,
			'dashboardType' => $this->dashboardType,
			'supersetStatus' => $this->supersetStatus,
			'needsInstall' => $this->needsInstall,
			'isFirstStartup' => $this->isFirstStartup,
		];
	}
}
