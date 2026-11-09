<?php

namespace Bitrix\Landing\Markup\Delivery;

use Bitrix\Crm\WebForm;
use Bitrix\Landing\Block;
use Bitrix\Landing\Markup\FileStore;
use Bitrix\Landing\Markup\Form\FormEmbedRenderer;
use Bitrix\Landing\Markup\Image\Placeholder;
use Bitrix\Landing\Markup\Manifest;
use Bitrix\Landing\Markup\Reference\ReferenceMatcher;
use Bitrix\Landing\Rights;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/**
 * Builds the links to the package files at serve time.
 *
 * Text files of a package are stored in the contract form: package paths and bx-asset:// tokens.
 * On serve every reference becomes a signed link to the file controller: the entry - in the data
 * of the frame, css and js - in the answer of the controller. A published block gets links
 * without expiry, a draft one - links of the current window, issued only to a user who may view
 * the page. The result is cached by the block, the manifest version, the surface and the window,
 * so a stored text is read from the storage once per version.
 *
 * A declared image without a file (a failed generation or download) is shown as a grey
 * placeholder of its aspect; the stored text keeps the token for the next regeneration.
 *
 * The entry document also gets the loaders of the CRM forms bound to its markers. The bindings are a part
 * of the manifest, so a new binding or design gets a new cache entry. The loader itself belongs to the CRM:
 * it gets a new address on every rebuild (a form saved, switched on or off, deleted), and the old file
 * is deleted. So an entry with forms is kept in the tagged cache under the tags the CRM clears then:
 * the page gets the new loader at once, and an unchanged form costs nothing on show. The tags are read
 * only for an entry rendered anew: a cached entry is served without the CRM.
 *
 * Manifests of the first version (older markup sites, not migrated) carry texts with direct
 * /upload/ addresses substituted by the intake: those addresses are rewritten to controller links.
 */
class LinkRewriter
{
	private const CACHE_TABLE = 'landing_markup_delivery';
	private const PUBLIC_CACHE_TTL = 2592000;
	private const FORM_ENTRY_CACHE_DIR = '/landing/markup_delivery/';
	// without the tags of the CRM an entry with forms is kept no longer than the anti-cache window of a loader
	private const UNTAGGED_FORM_ENTRY_TTL = 180;

	private FileLinkSigner $signer;
	private ReferenceMatcher $referenceMatcher;
	private FormEmbedRenderer $formEmbedRenderer;

	/** @var \Closure(int): ?string */
	private \Closure $readContent;

	/** @var \Closure(int): bool */
	private \Closure $canViewLanding;

	/** @var \Closure(list<int>): list<string> */
	private \Closure $readFormCacheTags;

	/**
	 * @param callable(int): ?string|null $readContent Reader of a stored file by id.
	 * @param callable(int): bool|null $canViewLanding Whether the current user may view the landing.
	 * @param callable(list<int>): list<string>|null $readFormCacheTags Tags the CRM clears for the forms by ids;
	 * none when the CRM is not available.
	 */
	public function __construct(
		?FileLinkSigner $signer = null,
		?callable $readContent = null,
		?callable $canViewLanding = null,
		?FormEmbedRenderer $formEmbedRenderer = null,
		?callable $readFormCacheTags = null
	)
	{
		$this->signer = $signer ?? new FileLinkSigner();
		$this->referenceMatcher = new ReferenceMatcher();
		$this->formEmbedRenderer = $formEmbedRenderer ?? new FormEmbedRenderer();
		$this->readContent = $readContent
			? \Closure::fromCallable($readContent)
			: static fn(int $fileId): ?string => FileStore::readContent($fileId);
		$this->canViewLanding = $canViewLanding
			? \Closure::fromCallable($canViewLanding)
			: static fn(int $landingId): bool => Rights::hasAccessForLanding($landingId, Rights::ACCESS_TYPES['read']);
		$this->readFormCacheTags = $readFormCacheTags
			? \Closure::fromCallable($readFormCacheTags)
			: static fn(array $formIds): array => Loader::includeModule('crm')
				? array_map(static fn(int $formId): string => WebForm\Form::getCacheTag($formId), $formIds)
				: []
		;
	}

	/**
	 * Substitutes every reference of the text to a package file with a controller link.
	 * @param int|null $expiresAt Expiry of draft links; ignored for a public surface.
	 */
	public function rewrite(string $text, Manifest $manifest, int $blockId, bool $isPublic, ?int $expiresAt): string
	{
		$expiresAt = $isPublic ? null : $expiresAt;

		if ($manifest->getVersion() < 2)
		{
			return $this->rewriteLegacy($text, $manifest, $blockId, $expiresAt);
		}

		$urlByPath = [];
		$urlByToken = [];
		foreach ($manifest->getImages() as $image)
		{
			$urlByToken[$image['token']] = Placeholder::getUrl((string)($image['aspect'] ?? ''));
		}
		foreach ($manifest->getAssets() as $asset)
		{
			$url = $this->signer->getUrl($blockId, (int)$asset['fileId'], $expiresAt);
			if (isset($asset['token']))
			{
				$urlByToken[(string)$asset['token']] = $url;
			}
			else
			{
				$urlByPath[(string)$asset['path']] = $url;
			}
		}

		return $this->referenceMatcher->replace($text, $urlByPath, $urlByToken);
	}

	/**
	 * Entry document of the block version for the frame data, links included.
	 * Null when the entry cannot be read or the user may not get draft links.
	 */
	public function getEntrySource(Block $block, Manifest $manifest): ?string
	{
		$isPublic = $block->isPublic();
		if (!$isPublic && !($this->canViewLanding)((int)$block->getLandingId()))
		{
			return null;
		}

		return $this->getRewrittenFile($manifest, (int)$block->getId(), $manifest->getEntryFileId(), $isPublic);
	}

	/**
	 * Text file of the block version with links of the surface the file is requested by.
	 * Null when the file cannot be read.
	 * @param int|null $expiresAt Expiry of the draft link the file is requested by.
	 */
	public function getFileText(
		Manifest $manifest,
		int $blockId,
		int $fileId,
		bool $isPublic,
		?int $expiresAt = null
	): ?string
	{
		return $this->getRewrittenFile($manifest, $blockId, $fileId, $isPublic, $expiresAt);
	}

	private function getRewrittenFile(
		Manifest $manifest,
		int $blockId,
		int $fileId,
		bool $isPublic,
		?int $expiresAt = null
	): ?string
	{
		if ($fileId <= 0)
		{
			return null;
		}

		$expiresAt = $isPublic ? null : ($expiresAt ?? $this->signer->getDraftExpiresAt());
		$ttl = $isPublic ? self::PUBLIC_CACHE_TTL : max(1, $expiresAt - time());
		$cacheId = 'markup_file_' . md5(implode('|', [
			$blockId,
			$fileId,
			md5($manifest->toJson()),
			$isPublic ? 'public' : 'draft',
			(int)$expiresAt,
		]));

		if ($fileId === $manifest->getEntryFileId() && $manifest->getForms())
		{
			return $this->getEntryWithForms($manifest, $blockId, $isPublic, $expiresAt, $ttl, $cacheId);
		}

		$cache = Application::getInstance()->getManagedCache();
		if ($cache->read($ttl, $cacheId, self::CACHE_TABLE))
		{
			$cached = $cache->get($cacheId);
			if (is_string($cached))
			{
				return $cached;
			}
		}

		$rewritten = $this->buildFile($manifest, $blockId, $fileId, $isPublic, $expiresAt);
		if ($rewritten !== null)
		{
			$cache->setImmediate($cacheId, $rewritten);
		}

		return $rewritten;
	}

	private function getEntryWithForms(
		Manifest $manifest,
		int $blockId,
		bool $isPublic,
		?int $expiresAt,
		int $ttl,
		string $cacheId
	): ?string
	{
		$isTaggable = $this->isFormCacheTaggable();
		if (!$isTaggable)
		{
			$ttl = min($ttl, self::UNTAGGED_FORM_ENTRY_TTL);
		}

		$cacheDir = self::FORM_ENTRY_CACHE_DIR . $blockId;
		$cache = Application::getInstance()->getCache();
		$cache->noOutput();
		if ($cache->initCache($ttl, $cacheId, $cacheDir))
		{
			$cached = $cache->getVars();
			if (is_string($cached))
			{
				return $cached;
			}
		}

		$entry = $this->buildFile($manifest, $blockId, $manifest->getEntryFileId(), $isPublic, $expiresAt);
		if ($entry === null)
		{
			return null;
		}

		$tags = $isTaggable ? $this->getFormCacheTags($manifest) : [];
		// an entry the CRM could not tag now would be read back with the long ttl: it is not kept
		if (($isTaggable && !$tags) || !$cache->startDataCache($ttl, $cacheId, $cacheDir))
		{
			return $entry;
		}

		if ($tags)
		{
			$taggedCache = Application::getInstance()->getTaggedCache();
			$taggedCache->startTagCache($cacheDir);
			foreach ($tags as $tag)
			{
				$taggedCache->registerTag($tag);
			}
			$taggedCache->endTagCache();
		}
		$cache->endDataCache($entry);

		return $entry;
	}

	/**
	 * Whether the CRM clears the tags of the forms. Checked without the CRM itself: a cached entry
	 * is read with the ttl this gives.
	 */
	private function isFormCacheTaggable(): bool
	{
		return defined('BX_COMP_MANAGED_CACHE') && ModuleManager::isModuleInstalled('crm');
	}

	/**
	 * Tags the CRM clears when it rebuilds or deletes the loader of a bound form.
	 * @return list<string>
	 */
	private function getFormCacheTags(Manifest $manifest): array
	{
		$formIds = [];
		foreach ($manifest->getForms() as $binding)
		{
			$formIds[] = (int)$binding['formId'];
		}

		return ($this->readFormCacheTags)(array_values(array_unique($formIds)));
	}

	private function buildFile(
		Manifest $manifest,
		int $blockId,
		int $fileId,
		bool $isPublic,
		?int $expiresAt
	): ?string
	{
		$content = ($this->readContent)($fileId);
		if ($content === null)
		{
			return null;
		}

		$rewritten = $this->rewrite($content, $manifest, $blockId, $isPublic, $expiresAt);
		if ($fileId === $manifest->getEntryFileId())
		{
			$rewritten = $this->formEmbedRenderer->render($rewritten, $manifest, $isPublic);
		}

		return $rewritten;
	}

	private function rewriteLegacy(string $text, Manifest $manifest, int $blockId, ?int $expiresAt): string
	{
		$urlByAddress = [];
		foreach ($manifest->getAssets() as $asset)
		{
			$fileId = (int)$asset['fileId'];
			$address = (string)\CFile::getPath($fileId);
			if ($address !== '')
			{
				$urlByAddress[$address] = $this->signer->getUrl($blockId, $fileId, $expiresAt);
			}
		}

		$addresses = array_keys($urlByAddress);
		usort($addresses, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
		foreach ($addresses as $address)
		{
			$text = str_replace($address, $urlByAddress[$address], $text);
		}

		return $text;
	}
}
