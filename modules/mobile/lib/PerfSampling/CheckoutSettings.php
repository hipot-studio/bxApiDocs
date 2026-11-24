<?php

declare(strict_types=1);

namespace Bitrix\Mobile\PerfSampling;

use Bitrix\Main\Config\Configuration;

/**
 * The perfSampling key of the checkout response: whether the app samples its performance metrics and
 * where it posts them. Both values come from the "mobile" section of .settings.php, so the cloud switches
 * them per group of portals without a release:
 *
 * 'mobile' => ['value' => ['perf_sampling' => ['enabled' => true, 'endpoint' => 'https://…']]]
 *
 * The app reads the key by name: rename only together with the app.
 */
final class CheckoutSettings
{
	public const CHECKOUT_KEY = 'perfSampling';
	public const CONFIGURATION_SECTION = 'mobile';
	public const CONFIGURATION_KEY = 'perf_sampling';

	private readonly Configuration $configuration;

	public function __construct(
		private readonly EndpointValidator $endpointValidator = new EndpointValidator(),
		?Configuration $configuration = null,
	)
	{
		$this->configuration = $configuration ?? Configuration::getInstance();
	}

	/**
	 * The endpoint survives a switched off flag on purpose: "enabled: false" with the address is how the app
	 * learns to drop the batches queued for it, while a removed address leaves them until they expire.
	 *
	 * @return array{enabled: bool, endpoint: string}|null null keeps the key out of the response:
	 *    the app treats a missing key as switched off.
	 */
	public function getCheckoutValue(): ?array
	{
		$section = $this->configuration->get(self::CONFIGURATION_SECTION);

		return $this->buildCheckoutValue(is_array($section) ? ($section[self::CONFIGURATION_KEY] ?? null) : null);
	}

	/**
	 * @return array{enabled: bool, endpoint: string}|null
	 */
	private function buildCheckoutValue(mixed $settings): ?array
	{
		if (!is_array($settings))
		{
			return null;
		}

		$endpoint = $settings['endpoint'] ?? null;
		if (!is_string($endpoint) || !$this->endpointValidator->isValid($endpoint))
		{
			return null;
		}

		return [
			'enabled' => ($settings['enabled'] ?? false) === true,
			'endpoint' => $endpoint,
		];
	}
}
