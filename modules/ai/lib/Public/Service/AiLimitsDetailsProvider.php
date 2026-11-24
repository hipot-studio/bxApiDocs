<?php

declare(strict_types=1);

namespace Bitrix\AI\Public\Service;

use Bitrix\AI\Public\Dto\PortalQuotaDetails;
use Bitrix\AI\Public\Dto\QuotaDetailsUnavailableReason;
use Bitrix\AI\Public\Dto\SeatQuotaDetails;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Public\Service\AiLimitsService;

/**
 * Limit details of an employee and of the portal for the limit wall, from AiLimitsService of
 * vibecodeconnector.
 *
 * The cache, pauses and timeouts belong to AiLimitsService: every call here asks it exactly once,
 * without retries. Without the module or the service, on an empty answer, on a failure or when
 * the answer cannot be read, null is given and lastUnavailableReason() tells why. Nothing is
 * thrown.
 */
final class AiLimitsDetailsProvider
{
	private const MODULE_ID = 'vibecodeconnector';

	/** @var \Closure(): ?object */
	private readonly \Closure $limitsServiceResolver;
	private ?QuotaDetailsUnavailableReason $lastUnavailableReason = null;

	/**
	 * @param \Closure|null $limitsServiceResolver fn(): ?object — AiLimitsService or null when it is
	 *     not available; defaultResolver() when not given
	 */
	public function __construct(?\Closure $limitsServiceResolver = null)
	{
		$this->limitsServiceResolver = $limitsServiceResolver ?? self::defaultResolver();
	}

	/**
	 * AiLimitsService from the service locator when vibecodeconnector is installed and has it,
	 * otherwise null.
	 *
	 * @param \Closure|null $moduleLoader fn(string $moduleId): bool, Loader::includeModule when not given
	 * @param string $serviceClass the class to resolve
	 */
	public static function defaultResolver(
		?\Closure $moduleLoader = null,
		string $serviceClass = AiLimitsService::class,
	): \Closure
	{
		$moduleLoader ??= static fn (string $moduleId): bool => Loader::includeModule($moduleId);

		return static function () use ($moduleLoader, $serviceClass): ?object {
			if (!$moduleLoader(self::MODULE_ID) || !class_exists($serviceClass))
			{
				return null;
			}

			return ServiceLocator::getInstance()->get($serviceClass);
		};
	}

	/**
	 * @param int $userId the payer id the server-side caller determined; never taken from a request
	 */
	public function forUser(int $userId): ?SeatQuotaDetails
	{
		$details = $this->load(
			static fn (object $service): mixed => $service->getUser($userId),
			SeatQuotaDetails::fromPlatformData(...),
		);

		return $details instanceof SeatQuotaDetails ? $details : null;
	}

	public function forPortal(): ?PortalQuotaDetails
	{
		$details = $this->load(
			static fn (object $service): mixed => $service->getPortal(),
			PortalQuotaDetails::fromPlatformData(...),
		);

		return $details instanceof PortalQuotaDetails ? $details : null;
	}

	/**
	 * Why the last forUser()/forPortal() gave null; null after details were given.
	 */
	public function lastUnavailableReason(): ?QuotaDetailsUnavailableReason
	{
		return $this->lastUnavailableReason;
	}

	/**
	 * @param \Closure(object): mixed $request
	 * @param \Closure(array): (object|QuotaDetailsUnavailableReason) $interpret
	 */
	private function load(\Closure $request, \Closure $interpret): ?object
	{
		$this->lastUnavailableReason = null;

		try
		{
			$service = ($this->limitsServiceResolver)();
			if ($service === null)
			{
				$this->lastUnavailableReason = QuotaDetailsUnavailableReason::NoModule;

				return null;
			}

			$answer = $request($service);
		}
		catch (\Throwable)
		{
			$this->lastUnavailableReason = QuotaDetailsUnavailableReason::NoAnswer;

			return null;
		}

		if ($answer === null)
		{
			$this->lastUnavailableReason = QuotaDetailsUnavailableReason::NoAnswer;

			return null;
		}

		$details = is_array($answer) ? $interpret($answer) : QuotaDetailsUnavailableReason::Malformed;
		if ($details instanceof QuotaDetailsUnavailableReason)
		{
			$this->lastUnavailableReason = $details;

			return null;
		}

		return $details;
	}
}
