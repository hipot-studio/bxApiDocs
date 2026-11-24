<?php

declare(strict_types=1);

namespace Bitrix\AI\Limiter\Policy;

use Bitrix\AI\Config;
use Bitrix\AI\Limiter\Plan;
use Bitrix\Bitrix24\Feature;
use Bitrix\Bitrix24\Public\Provider\License\VibePlusPolicyProvider;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class TariffLimitPolicy
{
	private const MODULE_ID = 'ai';
	private const POLICY_ENABLED_OPTION = 'tariff_limit_policy_enabled';
	private const UNLIMITED_FEATURE = 'ai_unlimited_by_version';
	private const AI_LIMITS_FEATURE = 'ai_limits';
	private const MONTHLY_POOL_FEATURE = 'ai_monthly_pool_by_version';
	private const AI_DAILY_LIMIT_VARIABLE = 'ai_query_limit_day';
	private const AGENT_DAILY_LIMIT_VARIABLE = 'ai_agent_query_limit_day';
	private const LOGGER_ID = 'ai.limiter.tariff_limit_policy';

	private readonly Closure $optionProvider;
	private readonly Closure $moduleAvailableProvider;
	private readonly Closure $vibePlusTariffLineAvailableProvider;
	private readonly Closure $checkLimitsProvider;
	private readonly Closure $featureChargeableProvider;
	private readonly Closure $featureEnabledProvider;
	private readonly Closure $variableProvider;
	private readonly Closure $monthlyPoolLimitProvider;
	private readonly LoggerInterface $logger;
	private ?LimitPolicyMode $mode = null;

	public function __construct(
		?Closure $optionProvider = null,
		?Closure $moduleAvailableProvider = null,
		?Closure $vibePlusTariffLineAvailableProvider = null,
		?Closure $checkLimitsProvider = null,
		?Closure $featureChargeableProvider = null,
		?Closure $featureEnabledProvider = null,
		?Closure $variableProvider = null,
		?Closure $monthlyPoolLimitProvider = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->optionProvider = $optionProvider
			?? static fn(): string => Option::get(self::MODULE_ID, self::POLICY_ENABLED_OPTION, 'N');
		$this->moduleAvailableProvider = $moduleAvailableProvider
			?? static fn(): bool => Loader::includeModule('bitrix24');
		$this->vibePlusTariffLineAvailableProvider = $vibePlusTariffLineAvailableProvider
			?? static fn(): bool => class_exists(VibePlusPolicyProvider::class)
				&& (new VibePlusPolicyProvider())->isActive();
		$this->checkLimitsProvider = $checkLimitsProvider
			?? static fn(): mixed => Config::getValue('check_limits');
		$this->featureChargeableProvider = $featureChargeableProvider
			?? static fn(string $feature): bool => Feature::isFeatureChargeable($feature);
		$this->featureEnabledProvider = $featureEnabledProvider
			?? static fn(string $feature): bool => Feature::isFeatureEnabled($feature);
		$this->variableProvider = $variableProvider
			?? static fn(string $variable): mixed => Feature::getVariable($variable);
		$this->monthlyPoolLimitProvider = $monthlyPoolLimitProvider
			?? static fn(): ?int => Plan::createByB24()?->getMaxUsage();
		$this->logger = $logger ?? (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	/**
	 * Returns the active tariff limit policy mode.
	 *
	 * @return LimitPolicyMode
	 */
	public function getMode(): LimitPolicyMode
	{
		return $this->mode ??= $this->resolveMode();
	}

	/**
	 * Returns the daily limit for regular AI requests.
	 *
	 * @return int|null
	 */
	public function getAiDailyLimit(): ?int
	{
		return $this->normalizeLimit(($this->variableProvider)(self::AI_DAILY_LIMIT_VARIABLE));
	}

	/**
	 * Returns the daily limit for AI agent requests.
	 *
	 * @return int|null
	 */
	public function getAgentDailyLimit(): ?int
	{
		return $this->normalizeLimit(($this->variableProvider)(self::AGENT_DAILY_LIMIT_VARIABLE));
	}

	/**
	 * Returns the shared monthly pool limit.
	 *
	 * @return int|null
	 */
	public function getMonthlyPoolLimit(): ?int
	{
		return $this->normalizeLimit(($this->monthlyPoolLimitProvider)());
	}

	private function resolveMode(): LimitPolicyMode
	{
		try
		{
			if (($this->optionProvider)() !== 'Y')
			{
				return LimitPolicyMode::Legacy;
			}

			if (!($this->moduleAvailableProvider)())
			{
				return LimitPolicyMode::Legacy;
			}

			if (!($this->vibePlusTariffLineAvailableProvider)())
			{
				return LimitPolicyMode::Legacy;
			}

			if (($this->checkLimitsProvider)() !== 'Y')
			{
				return LimitPolicyMode::Unlimited;
			}

			if ($this->isFeatureAvailable(self::UNLIMITED_FEATURE))
			{
				return LimitPolicyMode::Unlimited;
			}

			$transitionPeriodActive = $this->isFeatureAvailable(self::AI_LIMITS_FEATURE);
			$monthlyPoolActive = $this->isFeatureAvailable(self::MONTHLY_POOL_FEATURE);

			if ($transitionPeriodActive && $monthlyPoolActive)
			{
				$this->logger->warning(
					'Tariff limit policy declarations conflict: transition and monthly pool are enabled.',
				);
			}

			if ($transitionPeriodActive)
			{
				return LimitPolicyMode::Transition;
			}

			if ($monthlyPoolActive)
			{
				if ($this->getMonthlyPoolLimit() === null)
				{
					$this->logger->warning(
						'Tariff limit policy monthly pool has no configured package; legacy limits are used.',
					);

					return LimitPolicyMode::Legacy;
				}

				return LimitPolicyMode::MonthlyPool;
			}

			if ($this->getAiDailyLimit() === null)
			{
				$this->logger->warning(
					'Tariff limit policy daily mode has no valid AI daily limit; legacy limits are used.',
				);

				return LimitPolicyMode::Legacy;
			}

			return LimitPolicyMode::DailyOnly;
		}
		catch (Throwable $exception)
		{
			$this->logger->warning(
				'Unable to resolve tariff limit policy; legacy limits are used.',
				['exception' => $exception],
			);

			return LimitPolicyMode::Legacy;
		}
	}

	private function isFeatureAvailable(string $feature): bool
	{
		return ($this->featureChargeableProvider)($feature)
			&& ($this->featureEnabledProvider)($feature);
	}

	private function normalizeLimit(mixed $limit): ?int
	{
		return is_int($limit) && $limit >= 0 ? $limit : null;
	}
}
