<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\Embedding;
use Bitrix\Crm\V2\Internal\Exception\WebForm\EmbeddingSelectionException;
use Bitrix\Crm\V2\Internal\Repository\WebForm\EmbeddingRepository;
use Bitrix\Crm\WebForm\Script;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;

/**
 * Builds a request-scoped legacy snippet without changing the stored form or its resource package.
 *
 * @internal
 */
final class EmbeddingSnippetBuilder
{
	private const BUTTON_TYPE_BUTTON = 'button';

	private const BUTTON_TYPE_LINK = 'link';

	private readonly \Closure $linkCodesLoader;
	private readonly \Closure $embeddingLoader;
	private readonly \Closure $requestContextLoader;
	private readonly \Closure $scriptFactory;
	private readonly \Closure $portalDomainLoader;

	/**
	 * @param null|\Closure(int): null|array{code: string, securityCode: string} $linkCodesLoader
	 * @param null|\Closure(int): ?Embedding $embeddingLoader
	 * @param null|\Closure(): array{https: bool, language: string, address: PortalAddress} $requestContextLoader
	 * @param null|\Closure(string, bool): Script $scriptFactory
	 * @param null|\Closure(): string $portalDomainLoader
	 */
	public function __construct(
		?\Closure $linkCodesLoader = null,
		?\Closure $embeddingLoader = null,
		?\Closure $requestContextLoader = null,
		?\Closure $scriptFactory = null,
		?\Closure $portalDomainLoader = null,
	)
	{
		$this->linkCodesLoader = $linkCodesLoader ?? static fn (int $formId): ?array =>
			(new EmbeddingRepository())->getLinkCodesByFormId($formId)
		;
		$this->embeddingLoader = $embeddingLoader ?? static fn (int $formId): ?Embedding =>
			(new EmbeddingRepository())->getByFormId($formId)
		;
		$this->requestContextLoader = $requestContextLoader ?? static function (): array {
			$request = Context::getCurrent()->getRequest();

			return [
				'https' => $request->isHttps(),
				'language' => Context::getCurrent()->getLanguage(),
				'address' => PortalAddress::of($request),
			];
		};
		$this->scriptFactory = $scriptFactory ?? static fn (string $domain, bool $https): Script =>
			new Script($domain, $https)
		;
		$this->portalDomainLoader = $portalDomainLoader ?? static function (): string {
			if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '')
			{
				return (string)SITE_SERVER_NAME;
			}

			return Option::get('main', 'server_name', '');
		};
	}

	public function buildClick(int $formId, string $buttonText, string $buttonType): ?string
	{
		if (!in_array($buttonType, [self::BUTTON_TYPE_BUTTON, self::BUTTON_TYPE_LINK], true))
		{
			throw new EmbeddingSelectionException(
				'buttonType',
				sprintf('Unknown embedding button type `%s`', $buttonType),
			);
		}

		$generator = $this->prepareGenerator($formId);
		if ($generator === null)
		{
			return null;
		}

		[$script, $params] = $generator;
		$params['button_caption'] = $buttonText;

		return $buttonType === self::BUTTON_TYPE_LINK
			? $script->getLink($params)
			: $script->getButton($params)
		;
	}

	public function buildAuto(int $formId, int $delay): ?string
	{
		$generator = $this->prepareGenerator($formId);
		if ($generator === null)
		{
			return null;
		}

		[$script, $params] = $generator;
		$params['delay'] = $delay;

		return $script->getDelay($params);
	}

	/**
	 * @return null|array{0: Script, 1: array{id: int, lang: string, sec: string}}
	 */
	private function prepareGenerator(int $formId): ?array
	{
		$linkCodes = ($this->linkCodesLoader)($formId);
		if (!is_array($linkCodes))
		{
			return null;
		}

		$embedding = ($this->embeddingLoader)($formId);
		if (!$embedding instanceof Embedding || !$embedding->resourcesReady)
		{
			return null;
		}

		$portalDomain = trim(($this->portalDomainLoader)());
		if ($portalDomain === '')
		{
			return null;
		}

		$context = ($this->requestContextLoader)();
		$script = ($this->scriptFactory)(
			self::appendRequestPort($portalDomain, $context['address']),
			$context['https'],
		);

		return [
			$script,
			[
				'id' => $formId,
				'lang' => $context['language'],
				'sec' => $linkCodes['securityCode'],
			],
		];
	}

	/**
	 * The port of the request, and only for the domain that answered it: a portal reached on a port
	 * of its own opens nothing on the standard one, while a stored domain that is not the host of
	 * this request is not known to answer on this port at all - and one carrying a port already has
	 * named the port it answers on.
	 *
	 * The host of the request decides the port and nothing else. The address a snippet published on
	 * a site of somebody else loads its code from is the domain the portal calls itself by, and a
	 * portal that calls itself nothing is answered with no snippet at all: a `Host` the caller sends
	 * must not become the origin of a `<script src>` running for every visitor of that site.
	 */
	private static function appendRequestPort(string $portalDomain, PortalAddress $address): string
	{
		if ($address->port === '' || strcasecmp($portalDomain, $address->host) !== 0)
		{
			return $portalDomain;
		}

		return $portalDomain . $address->port;
	}
}
