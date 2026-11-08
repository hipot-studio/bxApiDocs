<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingVariant;
use Bitrix\Crm\V2\Internal\Integration\Bitrix24\PortalRestrictionGateway;
use Bitrix\Crm\WebForm\Internals\LandingTable;
use Bitrix\Crm\WebForm\Telemetry\SourceContextSigner;
use Bitrix\Crm\WebForm\Telemetry\SubmissionClassifier;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Uri;
use InvalidArgumentException;

/**
 * Builds the links under which a CRM form is published: the public one and the preview of every
 * embedding variant.
 *
 * Reading a link changes nothing on the portal. Legacy resolves the address of the portal on the
 * same path and persists it back into `crm/portal_protocol_url` on every mismatch; this builder
 * only reads that option, and never as the first source.
 *
 * @internal
 */
final class PublicUrlBuilder
{
	/** Path template of a public form while the portal has stored none of its own. */
	public const DEFAULT_PUBLIC_FORM_PATH = '/pub/form/#form_code#/#form_sec#/';

	private const PORTAL_URL_OPTION = 'portal_protocol_url';

	private const PUBLIC_FORM_PATH_OPTION = 'webform_public_form_path';

	private const PREVIEW_MODE_PARAM = 'view';

	private const PREVIEW_MODE = 'preview';

	private const PREVIEW_VARIANT_PARAM = 'preview';

	private const PREVIEW_ENTITY_PARAM = 'preview_id';

	private const PREVIEW_VARIANTS = [
		EmbeddingVariant::INLINE,
		EmbeddingVariant::CLICK,
		EmbeddingVariant::AUTO,
	];

	/** The preview of a form shown by a site widget. */
	private const SITE_BUTTON_PREVIEW = 'button';

	/** The preview of a form greeting the visitors of an open line chat. */
	private const OPEN_LINE_PREVIEW = 'ol';

	private readonly \Closure $landingUrlLoader;
	private readonly \Closure $portalUrlLoader;
	private readonly \Closure $publicFormPathLoader;
	private readonly \Closure $urlProxy;
	private readonly \Closure $telemetrySigner;

	/**
	 * @param null|\Closure(int): ?string $landingUrlLoader public address of the form page, if the form has one
	 * @param null|\Closure(): string $portalUrlLoader scheme and host the links of this request lead to
	 * @param null|\Closure(): string $publicFormPathLoader path template of a public form
	 * @param null|\Closure(string): string $urlProxy platform-specific rewrite of a public link
	 * @param null|\Closure(int, string): string $telemetrySigner trusted telemetry context builder
	 */
	public function __construct(
		?\Closure $landingUrlLoader = null,
		?\Closure $portalUrlLoader = null,
		?\Closure $publicFormPathLoader = null,
		?\Closure $urlProxy = null,
		?\Closure $telemetrySigner = null,
	)
	{
		// The single-form read never touches the landing map, so no form page is generated behind it.
		$this->landingUrlLoader = $landingUrlLoader ?? static fn (int $formId): ?string =>
			LandingTable::getLandingPublicUrl($formId)
		;
		$this->portalUrlLoader = $portalUrlLoader ?? static fn (): string => self::readPortalUrl();
		$this->publicFormPathLoader = $publicFormPathLoader ?? static fn (): string =>
			(string)Option::get('crm', self::PUBLIC_FORM_PATH_OPTION, self::DEFAULT_PUBLIC_FORM_PATH)
		;
		$this->urlProxy = $urlProxy ?? static fn (string $url): string =>
			(new PortalRestrictionGateway())->proxyPublicUrl($url)
		;
		$this->telemetrySigner = $telemetrySigner ?? static fn (int $formId, string $source): string =>
			(new SourceContextSigner())->sign($formId, $source)
		;
	}

	/**
	 * The address a visitor of the form opens: the page of the form when the form has one, and the
	 * public path of the portal otherwise. Only the second one carries the security code of the form
	 * and only it is served through the platform proxy.
	 */
	public function buildPublicUrl(int $formId, string $formCode, string $securityCode): string
	{
		$landingUrl = ($this->landingUrlLoader)($formId);
		if (is_string($landingUrl) && $landingUrl !== '')
		{
			$url = $landingUrl;
		}
		else
		{
			$url = ($this->urlProxy)($this->buildFormPathUrl($formId, $formCode, $securityCode));
		}

		$telemetrySign = ($this->telemetrySigner)($formId, SubmissionClassifier::SOURCE_DIRECT_LINK);
		if ($telemetrySign === '')
		{
			return $url;
		}

		return (new Uri($url))
			->addParams([SourceContextSigner::PARAMETER => $telemetrySign])
			->getLocator()
		;
	}

	/**
	 * The address showing how one embedding variant looks on a page. Unlike the public link it always
	 * goes through the public path of the portal, so a form with a page of its own has a preview too;
	 * the variant is already part of the link and nothing is left for the caller to substitute.
	 */
	public function buildPreviewUrl(
		int $formId,
		string $formCode,
		string $securityCode,
		string $variant,
	): string
	{
		if (!in_array($variant, self::PREVIEW_VARIANTS, true))
		{
			throw new InvalidArgumentException(sprintf('Embedding variant `%s` has no preview', $variant));
		}

		return $this->buildPreviewUrlOf($formId, $formCode, $securityCode, $variant);
	}

	/**
	 * The address showing the form inside one site widget. The widget is part of the link, so a
	 * preview always leads to a particular widget and never to an empty one.
	 */
	public function buildSiteButtonPreviewUrl(
		int $formId,
		string $formCode,
		string $securityCode,
		int $siteButtonId,
	): string
	{
		return $this->buildPreviewUrlOf(
			$formId,
			$formCode,
			$securityCode,
			self::SITE_BUTTON_PREVIEW,
			self::requireEntityId($siteButtonId),
		);
	}

	/**
	 * The address showing the form inside one open line chat. A chat is shown by a site widget, so
	 * the entity of the link is that widget and a chat without one has no preview to build.
	 */
	public function buildOpenLinePreviewUrl(
		int $formId,
		string $formCode,
		string $securityCode,
		int $siteButtonId,
	): string
	{
		return $this->buildPreviewUrlOf(
			$formId,
			$formCode,
			$securityCode,
			self::OPEN_LINE_PREVIEW,
			self::requireEntityId($siteButtonId),
		);
	}

	private function buildPreviewUrlOf(
		int $formId,
		string $formCode,
		string $securityCode,
		string $preview,
		?int $entityId = null,
	): string
	{
		$params = [
			self::PREVIEW_MODE_PARAM => self::PREVIEW_MODE,
			self::PREVIEW_VARIANT_PARAM => $preview,
		];
		if ($entityId !== null)
		{
			$params[self::PREVIEW_ENTITY_PARAM] = (string)$entityId;
		}

		return (new Uri($this->buildFormPathUrl($formId, $formCode, $securityCode)))
			->addParams($params)
			->getLocator()
		;
	}

	/**
	 * Legacy builds the same link with an empty entity whenever it finds none, and such a link opens
	 * nothing. Here the absence of the entity is the caller's answer, not a link.
	 */
	private static function requireEntityId(int $entityId): int
	{
		if ($entityId <= 0)
		{
			throw new InvalidArgumentException(sprintf('Preview entity `%d` does not exist', $entityId));
		}

		return $entityId;
	}

	private function buildFormPathUrl(int $formId, string $formCode, string $securityCode): string
	{
		$path = strtr(($this->publicFormPathLoader)(), [
			'#id#' => (string)$formId,
			'#form_id#' => (string)$formId,
			'#form_code#' => $formCode,
			'#form_sec#' => $securityCode,
		]);

		return rtrim(($this->portalUrlLoader)(), '/') . $path;
	}

	/**
	 * The host of the current request, which is what every link of this response has to lead to, and
	 * the host of the last request that had one while this one has none.
	 *
	 * Which header the host and the port are read off, and what such a header may and may not carry,
	 * is stated once in {@see PortalAddress} - the same reading the snippet of an embedding variant
	 * is built from. There is no request outside one - an agent or the console - and only then the
	 * portal option answers.
	 */
	private static function readPortalUrl(): string
	{
		$request = Context::getCurrent()->getRequest();
		$address = PortalAddress::of($request);
		if ($address->host === '')
		{
			return (string)Option::get('crm', self::PORTAL_URL_OPTION, '');
		}

		return ($request->isHttps() ? 'https' : 'http') . '://' . $address->host . $address->port;
	}
}
