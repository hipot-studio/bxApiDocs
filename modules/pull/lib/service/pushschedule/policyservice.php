<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Pull\Feature\PushScheduleFeature;
use Bitrix\Pull\Push;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;

Loc::loadMessages(__FILE__);

final class PolicyService
{
	private const DEFAULT_RULE_NAME_FALLBACK = 'Other';
	private const DEFAULT_RULE_NAME_MESSAGE = 'PULL_PUSH_SCHEDULE_DEFAULT_RULE_NAME';
	private const AUTOMATIC_MODE_UNKNOWN = 'unknown';
	private const AUTOMATIC_REASON_INTEGRATION_UNAVAILABLE = 'integration_unavailable';
	private const AUTOMATIC_REASON_NOT_CONFIGURED = 'not_configured';

	public const ERROR_FEATURE_DISABLED = 'FEATURE_DISABLED';
	public const ERROR_AUTOMATIC_MODE_UNAVAILABLE = 'AUTOMATIC_MODE_UNAVAILABLE';
	public const ERROR_INTEGRATION_UNAVAILABLE = 'INTEGRATION_UNAVAILABLE';
	public const ERROR_INTERNAL = 'INTERNAL_ERROR';

	public function __construct(
		private readonly ?PolicyRepository $repository = null,
		private readonly ?PolicyValidator $validator = null,
		private readonly ?\Closure $featureResolver = null,
		private readonly ?\Closure $automaticAvailabilityResolver = null,
		private readonly ?\Closure $sourceResolver = null,
		private readonly ?\Closure $moduleLoader = null,
		private readonly ?\Closure $readinessResolver = null,
	)
	{
	}

	public function get(int $userId): Result
	{
		$result = new Result();
		try
		{
			$result->setData(['policy' => $this->buildDto($userId)]);
		}
		catch (\Throwable)
		{
			$result->addError(new Error('Cannot read push schedule policy.', self::ERROR_INTERNAL));
		}

		return $result;
	}

	public function save(
		int $userId,
		mixed $revision,
		mixed $automaticEnabled,
		mixed $rules,
		mixed $exceptionSourceIds,
	): Result
	{
		$result = new Result();
		if (!$this->isFeatureEnabled($userId))
		{
			return $result->addError(new Error('Push schedule feature is disabled.', self::ERROR_FEATURE_DISABLED));
		}

		$validationResult = ($this->validator ?? new PolicyValidator())->validate(
			$revision,
			$automaticEnabled,
			$rules,
			$exceptionSourceIds,
		);
		if (!$validationResult->isSuccess())
		{
			return $result->addErrors($validationResult->getErrors());
		}
		try
		{
			$sources = $this->getSources();
			$repository = $this->repository ?? new PolicyRepository();
			$availableSourceIds = array_fill_keys(array_column($sources, 'id'), true);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Cannot prepare push schedule policy.', self::ERROR_INTERNAL));
		}
		$policy = $validationResult->getData();
		foreach ($policy['exceptionSourceIds'] as $sourceId)
		{
			if (!isset($availableSourceIds[$sourceId]))
			{
				return $result->addError(new Error(
					'Invalid push schedule policy.',
					PolicyValidator::ERROR_VALIDATION,
					['field' => 'exceptionSourceIds', 'ruleIndex' => null],
				));
			}
		}
		$storedState = null;
		if ($policy['automaticEnabled'])
		{
			try
			{
				$storedState = $repository->getState($userId);
			}
			catch (\Throwable)
			{
				return $result->addError(new Error('Cannot read push schedule policy.', self::ERROR_INTERNAL));
			}
		}
		$isNewAutomaticMode = $storedState !== null
			&& $policy['revision'] === $storedState['revision']
			&& !$storedState['automaticEnabled'];
		try
		{
			$automatic = $isNewAutomaticMode
				? $this->getFreshAutomaticAvailability($userId)
				: $this->getAutomaticAvailability($userId);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Cannot read automatic mode availability.', self::ERROR_INTERNAL));
		}
		if (
			$isNewAutomaticMode
			&& (!($automatic['trusted'] ?? false) || !($automatic['available'] ?? false))
		)
		{
			try
			{
				$current = $this->buildDtoFromPolicy(
					$repository->get($userId),
					$this->createDtoContext(true, $automatic, $sources),
				);
			}
			catch (\Throwable)
			{
				return $result->addError(new Error(
					'Cannot read current push schedule policy.',
					self::ERROR_INTERNAL,
				));
			}
			if ($current['revision'] !== $policy['revision'])
			{
				return $result->addError(new Error(
					'Push schedule revision conflict.',
					PolicyRepository::ERROR_REVISION_CONFLICT,
					['current' => $current],
				));
			}

			return $result->addError(new Error(
				'Automatic push schedule mode is unavailable.',
				self::ERROR_AUTOMATIC_MODE_UNAVAILABLE,
				[
					'field' => 'automaticEnabled',
					'unavailableReason' => $this->mapUnavailableReason($automatic['unavailableReason'] ?? null),
					'current' => $current,
				],
			));
		}
		$dtoContext = $this->createDtoContext(true, $automatic, $sources);

		$saveResult = $repository->save(
			$userId,
			$policy,
			fn (array $policy): array => $this->buildDtoFromPolicy($policy, $dtoContext),
		);
		if ($saveResult->isSuccess())
		{
			$result->setData($saveResult->getData());

			return $result;
		}

		$error = $saveResult->getError();
		if ($error?->getCode() === PolicyRepository::ERROR_REVISION_CONFLICT)
		{
			try
			{
				$current = $this->buildDto($userId);
			}
			catch (\Throwable)
			{
				return $result->addError(new Error('Cannot read current push schedule policy.', self::ERROR_INTERNAL));
			}

			return $result->addError(new Error(
				'Push schedule revision conflict.',
				PolicyRepository::ERROR_REVISION_CONFLICT,
				['current' => $current],
			));
		}

		return $result->addErrors($saveResult->getErrors());
	}

	public function buildDto(int $userId): array
	{
		$available = $this->isFeatureEnabled($userId);
		if (!$available)
		{
			return $this->buildDtoFromPolicy(
				[
					'revision' => 0,
					'automaticEnabled' => false,
					'rules' => [],
					'exceptionSourceIds' => [],
				],
				$this->createDtoContext(false, $this->unavailableAutomaticMode(), []),
			);
		}

		return $this->buildDtoFromPolicy(
			$this->getStoredPolicy($userId),
			$this->createDtoContext(
				true,
				$this->getAutomaticAvailability($userId),
				$this->getSources(),
			),
		);
	}

	private function createDtoContext(bool $available, array $automatic, array $sources): array
	{
		return [
			'available' => $available,
			'automatic' => $automatic,
			'sources' => $sources,
		];
	}

	private function buildDtoFromPolicy(array $policy, array $context): array
	{
		$automatic = $context['automatic'];

		return [
			'available' => $context['available'],
			'revision' => $policy['revision'],
			'automatic' => [
				'enabled' => $policy['automaticEnabled'],
				'available' => $automatic['available'],
				'mode' => $automatic['mode'],
				'unavailableReason' => $this->mapUnavailableReason($automatic['unavailableReason']),
			],
			'rules' => $this->localizeDefaultRuleNames($policy['rules']),
			'exceptionSourceIds' => $policy['exceptionSourceIds'],
			'sources' => $context['sources'],
			'vacationPopupEligible' => $context['available']
				&& $policy['automaticEnabled']
				&& $automatic['available'],
		];
	}

	public function getStoredPolicy(int $userId): array
	{
		return ($this->repository ?? new PolicyRepository())->get($userId);
	}

	public function isFeatureEnabled(int $userId): bool
	{
		try
		{
			$enabled = $this->featureResolver !== null
				? (bool)($this->featureResolver)($userId)
				: Feature::isEnabled(PushScheduleFeature::class, new Context($userId));

			return $enabled && ($this->readinessResolver !== null
				? (bool)($this->readinessResolver)()
				: (new ReadinessService())->isReady());
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getAutomaticAvailability(int $userId): array
	{
		return $this->resolveAutomaticAvailability($userId, false);
	}

	private function getFreshAutomaticAvailability(int $userId): array
	{
		return $this->resolveAutomaticAvailability($userId, true);
	}

	private function resolveAutomaticAvailability(int $userId, bool $fresh): array
	{
		if ($this->automaticAvailabilityResolver !== null)
		{
			return ($this->automaticAvailabilityResolver)($userId);
		}

		if (!$this->includeModule('timeman'))
		{
			return $this->unavailableAutomaticMode();
		}

		try
		{
			$provider = new RecordProvider();
			$availability = $fresh
				? $provider->getFreshAutomaticModeAvailability($userId)
				: $provider->getAutomaticModeAvailability($userId);
		}
		catch (\Throwable)
		{
			return $this->unavailableAutomaticMode();
		}

		return [
			'trusted' => $availability->trusted,
			'available' => $availability->available,
			'mode' => $availability->mode,
			'unavailableReason' => $availability->unavailableReason,
		];
	}

	private function unavailableAutomaticMode(): array
	{
		return [
			'trusted' => false,
			'available' => false,
			'mode' => self::AUTOMATIC_MODE_UNKNOWN,
			'unavailableReason' => self::AUTOMATIC_REASON_INTEGRATION_UNAVAILABLE,
		];
	}

	private function mapUnavailableReason(?string $reason): ?string
	{
		return $reason === self::AUTOMATIC_REASON_NOT_CONFIGURED
			? 'worktime_not_configured'
			: $reason;
	}

	private function includeModule(string $moduleId): bool
	{
		return $this->moduleLoader !== null
			? (bool)($this->moduleLoader)($moduleId)
			: Loader::includeModule($moduleId);
	}

	private function localizeDefaultRuleNames(array $rules): array
	{
		$defaultName = Loc::getMessage(self::DEFAULT_RULE_NAME_MESSAGE) ?: self::DEFAULT_RULE_NAME_FALLBACK;
		foreach ($rules as &$rule)
		{
			if (($rule['name'] ?? null) === PolicyValidator::LEGACY_RULE_NAME_SENTINEL)
			{
				$rule['name'] = $defaultName;
			}
		}
		unset($rule);

		return $rules;
	}

	private function getSources(): array
	{
		return $this->sourceResolver !== null
			? ($this->sourceResolver)()
			: Push::getSources();
	}
}
