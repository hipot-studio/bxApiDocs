<?php
declare(strict_types=1);

namespace Bitrix\Disk\Document\OnlyOffice;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\EditedDocumentDownloaderInterface;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SaveFailureCategory;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SaveTimingConfig;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\IO\File;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Http\Response;
use Bitrix\Main\Web\Http\ResponseBuilder;
use Bitrix\Main\Web\Http\Stream;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;

/**
 * Downloads the document the editor server offers in a save callback.
 *
 * The url is a field of the body of the callback, so it names an address the portal was asked to fetch
 * and not one it trusts: only http and https are followed, and only towards the document server the
 * portal is configured with, or a host of the option of upload addresses - a server handing out an
 * address of its own, and a reverse proxy rewriting the Host, are reachable no other way. Those hosts
 * may well live in a private network of the box, which is why the list and not the range of the address
 * decides. A portal served by the document proxy cannot name its server - the proxy picks one per
 * session and the portal never learns which - so there a host outside the list is downloaded from only
 * while it is public, and loopback, link-local and private networks stay out of reach in every mode.
 * Redirects are not followed and the body is cut off at the size the editor is allowed to open.
 *
 * A hanging server is the other failure to guard against: the attempts stay inside the budget counted
 * from the start of the processing of the webhook, and the transfer of a single attempt ends at the
 * limit of the download as a whole, so the portal answers the callback instead of holding the connection
 * until the editor server gives up on it.
 */
final class FileDownloader implements EditedDocumentDownloaderInterface
{
	/** Hosts of upload addresses the portal accepts besides its configured document server. */
	public const OPTION_EXTRA_HOSTS = 'disk_onlyoffice_save_extra_hosts';

	private const ALLOWED_SCHEMES = ['http', 'https'];

	private readonly SaveTimingConfig $timingConfig;
	/** @var string[] */
	private readonly array $allowedServers;
	private readonly bool $acceptsUnlistedPublicServer;
	private readonly int $maxContentLength;

	/**
	 * @param string[]|null $allowedServers document servers the portal is configured with; the
	 *        configuration of the module answers when the caller names none.
	 * @param bool|null $acceptsUnlistedPublicServer whether a public host outside `$allowedServers` is
	 *        downloaded from as well; that is what a portal registered at the document proxy needs.
	 * @param string[]|null $extraHosts hosts accepted besides the configured servers, `host` or
	 *        `host:port`; the option of the module answers when the caller names none.
	 */
	public function __construct(
		?SaveTimingConfig $timingConfig = null,
		?array $allowedServers = null,
		?bool $acceptsUnlistedPublicServer = null,
		?int $maxContentLength = null,
		?array $extraHosts = null,
	)
	{
		$this->timingConfig = $timingConfig ?? SaveTimingConfig::fromModuleOptions();

		$configuration = null;
		if ($allowedServers === null || $acceptsUnlistedPublicServer === null || $maxContentLength === null)
		{
			$configuration = self::getConfiguration();
		}

		$this->allowedServers = self::merge(
			$allowedServers ?? self::readConfiguredServers($configuration),
			$extraHosts ?? self::readExtraHosts(),
		);
		$this->acceptsUnlistedPublicServer =
			$acceptsUnlistedPublicServer ?? ($configuration?->getCloudRegistrationData() !== null)
		;
		$this->maxContentLength = $maxContentLength ?? self::readMaxContentLength($configuration);
	}

	public function downloadToTempFile(string $url, float $processingStartedAt): Result
	{
		$url = trim($url);
		$uri = new Uri($url);
		$host = $uri->getHost();
		$startedAt = microtime(true);

		$refusal = $this->refuse($uri);
		if ($refusal !== null)
		{
			return (new Result())
				->addError(new Error($refusal, SaveFailureCategory::DownloadFailed->value))
				->setData($this->makeData(
					status: 0,
					attempts: 0,
					contentLength: null,
					host: $host,
					startedAt: $startedAt,
				))
			;
		}

		$allowsPrivateAddress = $this->isConfiguredServer($uri);
		$tmpFile = $this->getTempPath();

		$status = 0;
		$attemptsMade = 0;
		$oversized = false;

		for ($attempt = 1; $attempt <= $this->timingConfig->attempts; $attempt++)
		{
			if ($attempt > 1)
			{
				sleep($this->timingConfig->getPauseBeforeAttempt($attempt));
				if (microtime(true) - $processingStartedAt >= $this->timingConfig->downloadBudget)
				{
					break;
				}
			}

			$httpClient = new HttpClient([
				'socketTimeout' => $this->timingConfig->connectTimeout,
				'streamTimeout' => $this->timingConfig->streamTimeout,
				// The editor server answers a download with the document itself, so a redirect is either
				// a server which is not it or one which is not working; neither is followed.
				'redirect' => false,
				'bodyLengthMax' => $this->maxContentLength,
				'privateIp' => $allowsPrivateAddress,
			]);
			// A document too large to be taken is the same answer on every attempt, so it is told apart
			// from the transport breaking off on the very same limit, which is worth another try.
			$httpClient->shouldFetchBody(function(Response $response) use (&$oversized): bool {
				$announced = $response->getHeadersCollection()->get('Content-Length');
				$oversized = is_numeric($announced) && (int)$announced > $this->maxContentLength;

				return !$oversized;
			});
			$httpClient->setResponseBuilder($this->makeDeadlineResponseBuilder(
				$processingStartedAt + $this->timingConfig->downloadLimit,
			));

			$attemptsMade = $attempt;
			$downloaded = $httpClient->download($url, $tmpFile);
			$status = (int)$httpClient->getStatus();

			if ($oversized)
			{
				break;
			}

			if ($downloaded && $status === 200)
			{
				return (new Result())->setData($this->makeData(
					status: $status,
					attempts: $attemptsMade,
					contentLength: $this->readContentLength($httpClient),
					host: $host,
					startedAt: $startedAt,
					file: $tmpFile,
				));
			}

			// A network failure and a failure of the editor server itself are worth another attempt;
			// anything else the server answers means the same on every attempt.
			if ($status === 0 || ($status >= 500 && $status <= 599))
			{
				continue;
			}

			break;
		}

		$this->removeTempFile($tmpFile);

		$message = $oversized
			? "The document {$host} offers is larger than the portal downloads."
			: "Could not download the edited document from {$host}. Last status: {$status}."
		;

		return (new Result())
			->addError(new Error(
				$message,
				SaveFailureCategory::DownloadFailed->value,
			))
			->setData($this->makeData(
				status: $status,
				attempts: $attemptsMade,
				contentLength: null,
				host: $host,
				startedAt: $startedAt,
			))
		;
	}

	/**
	 * A response whose body stops taking bytes once the download has spent all the time it may.
	 *
	 * The http client of the core bounds the connecting and the going silent of a server, but not the
	 * transfer itself: its curl handler asks for no more than one byte a second, so a server dripping the
	 * document holds the worker for as long as it likes while the connection stays formally healthy. The
	 * body of the response is the only part of the transfer this module owns, so the limit is kept there -
	 * a write past the deadline raises, and both handlers of the core turn that into a broken-off request.
	 */
	private function makeDeadlineResponseBuilder(float $deadline): ResponseBuilder
	{
		return new class($deadline) extends ResponseBuilder {
			public function __construct(private readonly float $deadline)
			{
			}

			public function createFromString(string $response): Response
			{
				$built = parent::createFromString($response);

				return $built->withBody(new class($built->getBody(), $this->deadline) extends Stream {
					public function __construct($stream, private readonly float $deadline)
					{
						parent::__construct($stream);
					}

					public function write(string $string): int
					{
						if (microtime(true) >= $this->deadline)
						{
							throw new \RuntimeException(
								'The editor server sends the document slower than the download may last.',
							);
						}

						return parent::write($string);
					}
				});
			}
		};
	}

	/**
	 * Why the url of the callback is not downloaded from at all, or null when it is.
	 *
	 * The host is left out of the answer: it is data of the callback, and the log already carries it as
	 * a field of its own.
	 */
	private function refuse(Uri $uri): ?string
	{
		if (!in_array($uri->getScheme(), self::ALLOWED_SCHEMES, true))
		{
			return 'The save callback offers a url which is neither http nor https.';
		}

		if ($uri->getHost() === '')
		{
			return 'The save callback offers a url without a host.';
		}

		if ($this->isConfiguredServer($uri) || $this->acceptsUnlistedPublicServer)
		{
			return null;
		}

		return 'The save callback offers a url of a host which is not the document server of the portal.';
	}

	private function isConfiguredServer(Uri $uri): bool
	{
		foreach ($this->allowedServers as $server)
		{
			$serverUrl = self::withScheme($server);
			$serverHost = (new Uri($serverUrl))->getHost();
			if ($serverHost === '' || $serverHost !== $uri->getHost())
			{
				continue;
			}

			// Uri::getPort() answers the default port of the scheme, so the setting itself is asked
			// whether it names a port at all: a server configured without one accepts any.
			$serverPort = parse_url($serverUrl, PHP_URL_PORT);
			if ($serverPort === null || $serverPort === false || (int)$serverPort === $uri->getPort())
			{
				return true;
			}
		}

		return false;
	}

	private static function withScheme(string $server): string
	{
		return preg_match('~^https?://~i', $server) === 1 ? $server : "https://{$server}";
	}

	private static function getConfiguration(): ?Configuration
	{
		$locator = ServiceLocator::getInstance();
		$configuration = $locator->has('disk.onlyofficeConfiguration')
			? $locator->get('disk.onlyofficeConfiguration')
			: new Configuration()
		;

		return $configuration instanceof Configuration ? $configuration : null;
	}

	/**
	 * @return string[]
	 */
	private static function readConfiguredServers(?Configuration $configuration): array
	{
		if ($configuration === null)
		{
			return [];
		}

		$servers = [$configuration->getServer(), $configuration->getServer(original: true)];
		$servers = array_map(static fn($server) => is_string($server) ? trim($server) : '', $servers);

		return array_values(array_unique(array_filter($servers)));
	}

	/**
	 * Hosts the portal accepts the upload address from besides the server it is configured with.
	 *
	 * A document server may hand out an upload address of a host of its own, and a reverse proxy in front
	 * of it may rewrite the Host; neither matches the setting, and without this option the save is refused
	 * and the edits of the user are lost. The portal names those hosts itself, so they are trusted exactly
	 * as the configured server is - the port is compared by the same rule, and an entry naming none
	 * accepts any. Empty unless the portal fills it in, and an entry which is not an address matches no
	 * host and changes nothing.
	 *
	 * @return string[]
	 */
	private static function readExtraHosts(): array
	{
		$option = Option::get(Driver::INTERNAL_MODULE_ID, self::OPTION_EXTRA_HOSTS, '');
		$hosts = preg_split('~[\s,]+~', trim($option));

		return $hosts === false ? [] : $hosts;
	}

	/**
	 * @param string[] $servers
	 * @param string[] $extraHosts
	 * @return string[]
	 */
	private static function merge(array $servers, array $extraHosts): array
	{
		return array_values(array_unique(array_filter(array_merge($servers, $extraHosts))));
	}

	/**
	 * A save cannot be larger than what the editor is allowed to open, and never smaller than the
	 * default of the module: a portal which lowered the setting must not lose a document by it.
	 */
	private static function readMaxContentLength(?Configuration $configuration): int
	{
		return max((int)($configuration?->getMaxFileSize() ?? 0), Configuration::DEFAULT_MAX_FILESIZE);
	}

	private function makeData(
		int $status,
		int $attempts,
		?int $contentLength,
		string $host,
		float $startedAt,
		?string $file = null,
	): array
	{
		return [
			self::DATA_FILE => $file,
			self::DATA_HTTP_STATUS => $status,
			self::DATA_ATTEMPTS => $attempts,
			self::DATA_CONTENT_LENGTH => $contentLength,
			self::DATA_DOWNLOAD_MS => (int)round((microtime(true) - $startedAt) * 1000),
			self::DATA_DOC_SERVER_HOST => $host,
		];
	}

	private function readContentLength(HttpClient $httpClient): ?int
	{
		$contentLength = $httpClient->getHeaders()->get('Content-Length');

		return is_numeric($contentLength) ? (int)$contentLength : null;
	}

	private function removeTempFile(string $tmpFile): void
	{
		$file = new File($tmpFile);
		if ($file->isExists())
		{
			$file->delete();
		}
	}

	private function getTempPath(): string
	{
		$tmpFile = \CTempFile::getFileName(Random::getString(16));
		checkDirPath($tmpFile);

		return $tmpFile;
	}
}
