<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Disk\Document\Flipchart\BoardApiService;
use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Disk\Document\Flipchart\JwtService;
use Bitrix\Main\ArgumentException;

final class DefaultBoardApiServiceFactory implements BoardApiServiceFactory
{
	/**
	 * The old profile must call the constructor without an argument: the documentproxy branch lives
	 * inside `if (is_null($baseUrl))` and an explicit address bypasses it silently.
	 */
	public function create(?ServiceProfile $profile): BoardApiService
	{
		if ($profile === null)
		{
			throw new ArgumentException('Board service client must not be built for an unresolved profile');
		}

		if ($profile === ServiceProfile::New && Configuration::isUsingDocumentProxy())
		{
			// The address is the only thing this seam picks by profile; the token every method of the
			// client asks for is signed on the cloud proxy of the old instance whatever the profile is.
			// Building the client would therefore send credentials of the cloud to the new instance, so
			// the refusal is here, on the working path, and not only in the enabling procedure.
			$exception = new ConfigurationException(
				'boards_use_documentproxy is Y; the new service profile is not available on this portal',
			);

			PilotLog::error(
				'Board service profile is not available: {entryPoint}, {reason}',
				[
					'entryPoint' => 'BoardApiServiceFactory',
					'reason' => $exception->getMessage(),
				],
			);

			throw $exception;
		}

		// No default branch on purpose: this is the single seam that turns a profile into a network
		// address, so a profile added later must fail loudly here instead of falling through to Old.
		return match ($profile)
		{
			ServiceProfile::New => new BoardApiService(Configuration::getApiHost(ServiceProfile::New)),
			ServiceProfile::Old => new BoardApiService(),
		};
	}

	public function createForServerRead(?ServiceProfile $profile, JwtService $jwtService): BoardApiService
	{
		if ($profile === null)
		{
			throw new ArgumentException('Board service client must not be built for an unresolved profile');
		}

		$useDocumentProxy = Configuration::isUsingDocumentProxy();
		if ($profile === ServiceProfile::New && $useDocumentProxy)
		{
			throw new ConfigurationException(
				'boards_use_documentproxy is Y; the new service profile is not available on this portal',
			);
		}

		$apiUrl = match ($profile)
		{
			ServiceProfile::Old => Configuration::getApiHost(ServiceProfile::Old),
			ServiceProfile::New => Configuration::getApiHost(ServiceProfile::New),
		};
		if (!$useDocumentProxy && !ServiceAddress::isValid(rtrim($apiUrl, '/')))
		{
			throw new ConfigurationException('Board service profile has no valid API address');
		}

		return new BoardApiService($apiUrl, $jwtService, $profile);
	}
}
