<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\State;

use Bitrix\Main\Validation\Validator\JsonValidator;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\V2\Internal\Entity\State\StateFlagKey;
use Bitrix\Tasks\V2\Internal\Entity\State\StateFlags;
use Bitrix\Tasks\V2\Internal\Repository\UserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\OptionDictionary;

class StateFlagsService
{
	public function __construct(
		private readonly UserOptionRepositoryInterface $userOptionRepository,
	)
	{
	}

	public function get(OptionDictionary $option, int $userId): StateFlags
	{
		$raw = $this->userOptionRepository->get($option, $userId);

		return StateFlags::mapFromArray($this->decodeFlags($raw));
	}

	public function set(StateFlags $flags, OptionDictionary $option, int $userId): void
	{
		$value = $this->encodeFlags($flags);

		if (!empty($value))
		{
			$this->userOptionRepository->add($option, $userId, Json::encode($value));
		}
	}

	public function encodeFlags(StateFlags $flags): array
	{
		$value = [];

		if ($flags->matchesWorkTime !== null)
		{
			$value[StateFlagKey::MatchesWorkTime->value] = $flags->matchesWorkTime ? 'Y' : 'N';
		}
		if ($flags->needsControl !== null)
		{
			$value[StateFlagKey::NeedsControl->value] = $flags->needsControl ? 'Y' : 'N';
		}
		if ($flags->defaultRequireResult !== null)
		{
			$value[StateFlagKey::DefaultRequireResult->value] = $flags->defaultRequireResult ? 'Y' : 'N';
		}
		if ($flags->allowsTimeTracking !== null)
		{
			$value[StateFlagKey::AllowsTimeTracking->value] = $flags->allowsTimeTracking ? 'Y' : 'N';
		}

		return $value;
	}

	public function decodeFlags(mixed $raw): array
	{
		$data = [];

		$validator = new JsonValidator();
		if ($validator->validate($raw)->isSuccess())
		{
			$state = Json::decode($raw);

			if (isset($state[StateFlagKey::MatchesWorkTime->value]))
			{
				$data[StateFlagKey::MatchesWorkTime->value] = $this->castBoolValue($state[StateFlagKey::MatchesWorkTime->value]);
			}
			if (isset($state[StateFlagKey::NeedsControl->value]))
			{
				$data[StateFlagKey::NeedsControl->value] = $this->castBoolValue($state[StateFlagKey::NeedsControl->value]);
			}
			if (isset($state[StateFlagKey::DefaultRequireResult->value]))
			{
				$data[StateFlagKey::DefaultRequireResult->value] = $this->castBoolValue($state[StateFlagKey::DefaultRequireResult->value]);
			}
			if (isset($state[StateFlagKey::AllowsTimeTracking->value]))
			{
				$data[StateFlagKey::AllowsTimeTracking->value] = $this->castBoolValue($state[StateFlagKey::AllowsTimeTracking->value]);
			}
		}

		return $data;
	}

	private function castBoolValue(string $value): bool
	{
		return $value === 'Y';
	}
}
