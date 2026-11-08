<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Main\Web;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class NationalTrustStore
{
	public const OPTION_NAME = 'trust_national_ca';

	private const BUNDLE_PATH = __DIR__ . '/Certificates/russian_trusted_root_ca.pem';
	private const CERTIFICATE_HEADER = '-----BEGIN CERTIFICATE-----';
	private const CERTIFICATE_BLOCK_PATTERN = '/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s';

	private const BUNDLE_FINGERPRINTS = [
		'D26D2D0231B7C39F92CC738512BA54103519E4405D68B5BD703E9788CA8ECF31',
	];
	private const DEFAULT_ON_REGIONS = ['ru'];
	private const HASHED_CERTIFICATE_NAME_PATTERN = '/^[0-9a-f]{8}\.\d+$/i';

	private static ?array $sslContextParams = null;

	public function getSslContextParams(): array
	{
		self::$sslContextParams ??= $this->resolveSslContextParams();

		return self::$sslContextParams;
	}

	public function getVerifiedBundlePath(): ?string
	{
		$path = $this->getBundlePath();

		return $this->isBundleUsable($path) ? $path : null;
	}

	protected function isEnabled(): bool
	{
		$value = $this->getOptionValue();
		if ($value === '')
		{
			return $this->isEnabledByDefault();
		}

		return $value === 'Y';
	}

	public function isEnabledByDefault(): bool
	{
		return in_array($this->getRegion(), self::DEFAULT_ON_REGIONS, true);
	}

	protected function resolveSslContextParams(): array
	{
		if (!$this->isEnabled())
		{
			return [];
		}

		$bundlePath = $this->getBundlePath();
		if (!$this->isBundleUsable($bundlePath))
		{
			return [];
		}

		$systemCertDir = $this->getSystemCertDir();
		if ($systemCertDir === null)
		{
			return [];
		}

		return [
			'cafile' => $bundlePath,
			'capath' => $systemCertDir,
		];
	}

	protected function getOptionValue(): string
	{
		return (string)Option::get('rest', self::OPTION_NAME, '');
	}

	protected function getRegion(): ?string
	{
		return Application::getInstance()->getLicense()->getRegion();
	}

	protected function getBundlePath(): string
	{
		return self::BUNDLE_PATH;
	}

	protected function getSystemCertDirPath(): string
	{
		$locations = openssl_get_cert_locations();

		$configuredPath = (string)($locations['ini_capath'] ?? '');
		if ($configuredPath !== '')
		{
			return $configuredPath;
		}

		$environmentName = (string)($locations['default_cert_dir_env'] ?? '');
		$environmentPath = $environmentName === '' ? '' : (string)getenv($environmentName);
		if ($environmentPath !== '')
		{
			return $environmentPath;
		}

		return (string)($locations['default_cert_dir'] ?? '');
	}

	protected function getAllowedFingerprints(): array
	{
		return self::BUNDLE_FINGERPRINTS;
	}

	private function isBundleUsable(string $path): bool
	{
		if (!is_file($path) || !is_readable($path))
		{
			return false;
		}

		$bundle = file_get_contents($path);
		if (!is_string($bundle))
		{
			return false;
		}

		$certificates = $this->splitCertificates($bundle);
		if ($certificates === [])
		{
			return false;
		}

		foreach ($certificates as $certificate)
		{
			if (!$this->isAllowedCertificate($certificate))
			{
				return false;
			}
		}

		return true;
	}

	private function splitCertificates(string $bundle): array
	{
		if (preg_match_all(self::CERTIFICATE_BLOCK_PATTERN, $bundle, $matches) < 1)
		{
			return [];
		}

		return count($matches[0]) === substr_count($bundle, self::CERTIFICATE_HEADER) ? $matches[0] : [];
	}

	private function isAllowedCertificate(string $certificate): bool
	{
		if (openssl_x509_parse($certificate) === false)
		{
			return false;
		}

		$fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
		if (!is_string($fingerprint))
		{
			return false;
		}

		foreach ($this->getAllowedFingerprints() as $allowedFingerprint)
		{
			if (strcasecmp($fingerprint, $allowedFingerprint) === 0)
			{
				return true;
			}
		}

		return false;
	}

	private function getSystemCertDir(): ?string
	{
		$certDir = $this->getSystemCertDirPath();
		if ($certDir === '' || !is_dir($certDir))
		{
			return null;
		}

		return $this->hasHashedCertificate(rtrim($certDir, '/')) ? $certDir : null;
	}

	private function hasHashedCertificate(string $certDir): bool
	{
		foreach (glob($certDir . '/*.[0-9]*') ?: [] as $path)
		{
			if (preg_match(self::HASHED_CERTIFICATE_NAME_PATTERN, basename($path)) !== 1)
			{
				continue;
			}

			if ($this->isLookupReadyCertificate($path))
			{
				return true;
			}
		}

		return false;
	}

	private function isLookupReadyCertificate(string $path): bool
	{
		if (!is_file($path) || !is_readable($path))
		{
			return false;
		}

		$content = file_get_contents($path);
		if (!is_string($content))
		{
			return false;
		}

		$certificate = openssl_x509_parse($content);
		if (!is_array($certificate))
		{
			return false;
		}

		[$nameHash] = explode('.', basename($path));

		return strcasecmp($nameHash, (string)($certificate['hash'] ?? '')) === 0;
	}
}
