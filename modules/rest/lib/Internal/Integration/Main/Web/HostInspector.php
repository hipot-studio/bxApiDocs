<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Main\Web;

use Bitrix\Main\Web\Http\Socket\Stream;
use Bitrix\Main\Web\IpAddress;
use Bitrix\Main\Web\Uri;

class HostInspector
{
	private const MINISTRY_ISSUER_ORGANIZATION = 'The Ministry of Digital Development and Communications';

	private const SECURE_SCHEME = 'https';
	private const CONNECT_TIMEOUT = 3;

	private const REFUSED_RANGE_LIST = ['100.64.0.0/10'];

	public function __construct(
		private readonly NationalTrustStore $trustStore = new NationalTrustStore(),
	)
	{
	}

	public function inspect(string $origin): HostDiagnosis
	{
		$uri = new Uri($origin);
		$host = $uri->getHost();
		$port = $uri->getPort();

		if ($host === '' || $port === null)
		{
			return HostDiagnosis::Unknown;
		}

		$address = $this->resolveAddress($host);
		if ($address === null)
		{
			return HostDiagnosis::HostUnreachable;
		}

		$secure = $uri->getScheme() === self::SECURE_SCHEME;

		$socket = $this->openSocket($address, $host, $port, $secure ? $this->getChainSslOptions($host) : []);
		if ($socket === null)
		{
			return HostDiagnosis::HostUnreachable;
		}

		if (!$secure)
		{
			$socket->close();

			return HostDiagnosis::Unknown;
		}

		$chain = $this->readCertificateChain($socket);
		if ($chain === null)
		{
			return HostDiagnosis::Unknown;
		}

		$diagnosis = $this->getChainDiagnosis($chain);
		if ($diagnosis === HostDiagnosis::CertificateMincifry && !$this->isSignedByNationalRoot($address, $host, $port))
		{
			return HostDiagnosis::Unknown;
		}

		return $diagnosis;
	}

	protected function resolveAddress(string $host): ?string
	{
		$address = IpAddress::createByName($host);

		return $this->isReachableAddress($address) ? $address->get() : null;
	}

	private function isReachableAddress(IpAddress $address): bool
	{
		if ($address->isPrivate())
		{
			return false;
		}

		if (!$address->isIPv4())
		{
			return true;
		}

		foreach (self::REFUSED_RANGE_LIST as $range)
		{
			if ($address->matchRange($range))
			{
				return false;
			}
		}

		return true;
	}

	protected function openSocket(string $address, string $host, int $port, array $sslOptions): ?Stream
	{
		$socket = new Stream(
			'tcp://' . $address . ':' . $port,
			[
				'socketTimeout' => self::CONNECT_TIMEOUT,
				'streamTimeout' => self::CONNECT_TIMEOUT,
				'contextOptions' => $sslOptions === [] ? [] : ['ssl' => $sslOptions],
			],
		);

		try
		{
			$socket->connect();
		}
		catch (\RuntimeException)
		{
			return null;
		}

		return $socket;
	}

	private function readCertificateChain(Stream $socket): ?array
	{
		$encrypted = @$socket->enableCrypto();
		$params = stream_context_get_params($socket->getResource());
		$socket->close();

		if ($encrypted !== true)
		{
			return null;
		}

		$chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];

		return is_array($chain) ? $chain : [];
	}

	private function isSignedByNationalRoot(string $address, string $host, int $port): bool
	{
		$bundlePath = $this->trustStore->getVerifiedBundlePath();
		if ($bundlePath === null)
		{
			return false;
		}

		$socket = $this->openSocket($address, $host, $port, $this->getVerifyingSslOptions($host, $bundlePath));
		if ($socket === null)
		{
			return false;
		}

		$verified = @$socket->enableCrypto();
		$socket->close();

		return $verified === true;
	}

	private function getChainSslOptions(string $host): array
	{
		return [
			'verify_peer' => false,
			'verify_peer_name' => false,
			'allow_self_signed' => true,
			'capture_peer_cert_chain' => true,
			'SNI_enabled' => true,
			'peer_name' => $host,
		];
	}

	private function getVerifyingSslOptions(string $host, string $bundlePath): array
	{
		return [
			'verify_peer' => true,
			'verify_peer_name' => true,
			'allow_self_signed' => false,
			'SNI_enabled' => true,
			'peer_name' => $host,
			'cafile' => $bundlePath,
		];
	}

	protected function getChainDiagnosis(array $chain): HostDiagnosis
	{
		$leaf = null;

		foreach ($chain as $certificate)
		{
			$parsed = @openssl_x509_parse($certificate);
			if (!is_array($parsed))
			{
				continue;
			}

			$leaf ??= $parsed;

			if ($this->isMinistryIssuer($parsed['issuer'] ?? []))
			{
				return HostDiagnosis::CertificateMincifry;
			}
		}

		$issuer = $leaf['issuer'] ?? null;
		$subject = $leaf['subject'] ?? null;

		if (is_array($issuer) && $issuer === $subject)
		{
			return HostDiagnosis::CertificateUntrusted;
		}

		return HostDiagnosis::Unknown;
	}

	protected function isMinistryIssuer(array $issuer): bool
	{
		$organization = $issuer['O'] ?? '';
		$organizationList = is_array($organization) ? $organization : [$organization];

		foreach ($organizationList as $name)
		{
			if (is_string($name) && mb_stripos($name, self::MINISTRY_ISSUER_ORGANIZATION) !== false)
			{
				return true;
			}
		}

		return false;
	}
}
