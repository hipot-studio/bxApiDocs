<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy\VibePlusEditionTrialStrategy;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy\VibePlusFeatureTrialStrategy;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\VibeDemoAuditLog;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\ActivationFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\ActivationUnavailableException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\ResetFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\ResetNotAllowedException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\StateFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\TrialAlreadyUsedException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\TrialDurationInvalidException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\VibeDemoException;

class Activator
{
	private const SECONDS_IN_DAY = 86400;

	private readonly VibeDemoAuditLog $auditLog;
	private readonly TrialMarker $marker;
	private readonly array $strategies;

	public function __construct(ActivationStrategy ...$strategies)
	{
		$this->strategies = $strategies;
		$this->auditLog = new VibeDemoAuditLog();
		$this->marker = new TrialMarker();
	}

	public function getState(): Result
	{
		try
		{
			return (new Result())->setData([
				'activated' => $this->marker->isMarked(),
				'activatedAt' => $this->marker->getActivatedAt(),
				'expireDate' => $this->marker->getExpireDate(),
			]);
		}
		catch (\Throwable $exception)
		{
			return $this->exceptionResult(new StateFailedException($exception));
		}
	}

	public function activate(?int $days = null, ?string $iss = null): Result
	{
		$grantedDays = $days;
		$strategy = null;
		try
		{
			$this->validateDays($days);
			$strategy = $this->resolveStrategy();
			$activation = $this->runActivation($strategy, $days);
			$grantedDays = $activation['days'];
			$granted = $this->hasGrantedTrial($strategy);
			$result = $this->successResult($granted, $activation['expireDate']);
		}
		catch (VibeDemoException $exception)
		{
			$granted = $this->hasGrantedTrial($strategy);
			$result = $this->exceptionResult($exception);
		}
		catch (\Throwable $exception)
		{
			$this->auditLog->recordFailure($iss, $days, $this->hasGrantedTrial($strategy), $exception);

			return $this->exceptionResult(new ActivationFailedException($exception));
		}

		return $this->auditLog->recordResult($iss, $grantedDays, $granted, $result);
	}

	public function reset(?string $iss = null): Result
	{
		try
		{
			if (!$this->marker->isResetAllowed())
			{
				throw new ResetNotAllowedException();
			}

			$reset = $this->marker->reset();
			$this->auditLog->recordReset($iss, $reset);

			return (new Result())->setData(['reset' => $reset]);
		}
		catch (VibeDemoException $exception)
		{
			return $this->auditLog->recordResetRefusal($iss, $this->exceptionResult($exception));
		}
		catch (\Throwable $exception)
		{
			$this->auditLog->recordFailure($iss, null, granted: false, error: $exception);

			return $this->exceptionResult(new ResetFailedException($exception));
		}
	}

	private function hasGrantedTrial(?ActivationStrategy $strategy): bool
	{
		try
		{
			return $strategy?->hasGrantedTrial() ?? false;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function findExpireDate(ActivationStrategy $strategy): ?DateTime
	{
		try
		{
			return $strategy->findExpireDate();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function runActivation(ActivationStrategy $strategy, ?int $days): array
	{
		if (!$this->isVibePlusTrial($strategy) && $strategy->isActivated())
		{
			return $this->activationResult($strategy, $days);
		}

		$duration = $this->resolveDuration($strategy, $days);

		try
		{
			$strategy->activate($duration);

			if (!$strategy->isActivated())
			{
				throw new ActivationFailedException();
			}
		}
		catch (\Throwable $exception)
		{
			if ($this->hasGrantedTrial($strategy))
			{
				$this->markTrialActivated($strategy, $this->findExpireDate($strategy));
			}

			throw $exception;
		}

		return $this->activationResult($strategy, $duration);
	}

	private function activationResult(ActivationStrategy $strategy, ?int $days): array
	{
		$expireDate = $strategy->findExpireDate();
		if ($expireDate !== null)
		{
			$this->markTrialActivated($strategy, $expireDate);
		}

		return [
			'expireDate' => $expireDate?->format('c'),
			'days' => $days,
		];
	}

	private function resolveStrategy(): ActivationStrategy
	{
		foreach ($this->strategies as $strategy)
		{
			if ($strategy->isApplicable())
			{
				return $strategy;
			}
		}

		throw new ActivationUnavailableException();
	}

	private function validateDays(?int $days): void
	{
		if ($days !== null && ($days < 1 || $days > ActivationStrategy::MAX_DURATION_DAYS))
		{
			throw new TrialDurationInvalidException();
		}
	}

	private function resolveDuration(ActivationStrategy $strategy, ?int $days): int
	{
		if ($this->isVibePlusTrial($strategy) || !$this->marker->isMarked())
		{
			return $days ?? ActivationStrategy::DEFAULT_DURATION_DAYS;
		}

		$expireDate = $strategy->findExpireDate();
		if ($expireDate === null)
		{
			throw new TrialAlreadyUsedException();
		}

		return $this->countRemainingDays($expireDate);
	}

	private function countRemainingDays(DateTime $expireDate): int
	{
		$remainingSeconds = $expireDate->getTimestamp() - time();
		$remainingDays = max(1, (int)ceil($remainingSeconds / self::SECONDS_IN_DAY));

		return min(ActivationStrategy::MAX_DURATION_DAYS, $remainingDays);
	}

	private function markTrialActivated(ActivationStrategy $strategy, ?DateTime $expireDate): void
	{
		if ($this->hasGrantedTrial($strategy) || !$this->marker->isMarked())
		{
			$this->marker->mark(
				$expireDate,
				basename(str_replace('\\', '/', $strategy::class)),
				$this->isVibePlusTrial($strategy),
			);
		}
	}

	private function isVibePlusTrial(ActivationStrategy $strategy): bool
	{
		return $strategy instanceof VibePlusEditionTrialStrategy
			|| $strategy instanceof VibePlusFeatureTrialStrategy;
	}

	private function successResult(bool $granted, ?string $expireDate): Result
	{
		return (new Result())->setData([
			'activated' => true,
			'granted' => $granted,
			'expireDate' => $expireDate,
		]);
	}

	private function exceptionResult(VibeDemoException $exception): Result
	{
		return (new Result())->addError(new Error($exception->getMessage(), $exception->getErrorCode()));
	}
}
