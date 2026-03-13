<?php

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary;
use Bitrix\Main\Web\Json;

class Storage
{
	private static array $data = [];
	
	public function __construct(
		private readonly int $userId,
	) {
	}

	public function store(AnnualSummary\Collection $collection): void
	{
		\CUserOptions::SetOption(
			'intranet',
			'annual_summary_25',
			Json::encode($collection->toArray()),
			false,
			$this->userId,
		);

		self::$data[$this->userId] = [];
	}
	
	private function fetch(): array
	{
		try
		{
			if (empty(self::$data[$this->userId]))
			{
				self::$data[$this->userId] = Json::decode(\CUserOptions::GetOption('intranet', 'annual_summary_25', '', $this->userId));
			}

			return self::$data[$this->userId];
		}
		catch (\Exception)
		{
			return [];
		}
	}
	
	public function load(): AnnualSummary\Collection
	{
		return AnnualSummary\Collection::createByArray($this->fetch());
	}
	
	public function has(): bool
	{
		return !empty($this->fetch());
	}
}
