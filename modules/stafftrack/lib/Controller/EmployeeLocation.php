<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Infrastructure\ActionFilters\OfficeUnavailableFilter;
use Bitrix\StaffTrack\Public\Provider\EmployeeLocationBlockProvider;

class EmployeeLocation extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new OfficeUnavailableFilter(),
		];
	}

	public function configureActions(): array
	{
		return [
			'getBlock' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @return array{block: array<string, mixed>|null}
	 */
	public function getBlockAction(int $employeeId): array
	{
		$block = $this->getEmployeeLocationBlockProvider()->getBlock(
			(int)CurrentUser::get()->getId(),
			$employeeId,
			true,
		);

		return [
			'block' => $block?->toArray(),
		];
	}

	protected function getEmployeeLocationBlockProvider(): EmployeeLocationBlockProvider
	{
		return new EmployeeLocationBlockProvider();
	}
}
