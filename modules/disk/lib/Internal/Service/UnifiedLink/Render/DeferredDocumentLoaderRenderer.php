<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

use Bitrix\Disk\Controller\ShortLink;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

final class DeferredDocumentLoaderRenderer
{
	public const CONTENT_TYPE = 'text/html; charset=UTF-8';
	public const CACHE_CONTROL = 'no-store';

	/**
	 * @param ShortLinkOpening|null $shortLinkOpening A short opening loads the document by a continuation
	 * posted to its own address, so the address bar keeps the short address and the mode is chosen again.
	 * @param UnifiedLinkAccessLevel $accessLevel The mode the opening was checked in: the continuation keeps it.
	 */
	public function __construct(
		private readonly ?ShortLinkOpening $shortLinkOpening = null,
		private readonly UnifiedLinkAccessLevel $accessLevel = UnifiedLinkAccessLevel::Read,
	)
	{
	}

	public function render(): string
	{
		$languageId = htmlspecialcharsbx(Loc::getCurrentLang());
		$title = htmlspecialcharsbx(Loc::getMessage('DISK_DEFERRED_DOCUMENT_LOADER_TITLE') ?? '');
		$hint = $this->renderHint();
		$shortLink = $this->getShortLinkContinuation();
		$openNow = htmlspecialcharsbx(Loc::getMessage('DISK_DEFERRED_DOCUMENT_LOADER_OPEN_NOW') ?? '');
		$cancel = htmlspecialcharsbx(Loc::getMessage('DISK_DEFERRED_DOCUMENT_LOADER_CANCEL') ?? '');
		$countdownText = Json::encode(Loc::getMessage('DISK_DEFERRED_DOCUMENT_LOADER_COUNTDOWN') ?? '');
		$cancelledText = Json::encode(Loc::getMessage('DISK_DEFERRED_DOCUMENT_LOADER_CANCELLED') ?? '');

		return <<<HTML
<!doctype html>
<html lang="{$languageId}">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>{$title}</title>
	<style>
		body { margin: 0; background: #fff; color: #333; font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
		.disk-deferred-loader { display: none; box-sizing: border-box; min-height: 100vh; flex-direction: column; align-items: center; justify-content: center; gap: 16px; padding: 24px; text-align: center; }
		.disk-deferred-loader--shown { display: flex; }
		.disk-deferred-loader__spinner { width: 48px; height: 48px; border: 3px solid #e6e8ea; border-top-color: #2fc6f6; border-radius: 50%; animation: disk-deferred-loader-spin 1s linear infinite; }
		.disk-deferred-loader__title { margin: 0; font-size: 20px; font-weight: 600; }
		.disk-deferred-loader__hint { margin: 0; color: #6a737c; }
		.disk-deferred-loader__link { color: #2066b0; }
		.disk-deferred-loader__status { margin: 0; min-height: 1.5em; }
		.disk-deferred-loader__button {
			min-width: 160px; padding: 8px 20px; border: 1px solid #2066b0; border-radius: 6px;
			background: #fff; color: #2066b0; font: inherit; cursor: pointer;
		}
		.disk-deferred-loader__button:hover { background: #eef5fc; }
		.disk-deferred-loader__button:focus-visible { outline: 2px solid #2066b0; outline-offset: 2px; }
		@keyframes disk-deferred-loader-spin { to { transform: rotate(360deg); } }
		@media (prefers-reduced-motion: reduce) { .disk-deferred-loader__spinner { animation: none; } }
	</style>
</head>
<body>
	<main class="disk-deferred-loader" id="deferred-document-loader">
		<div class="disk-deferred-loader__spinner" aria-hidden="true"></div>
		<h1 class="disk-deferred-loader__title">{$title}</h1>
		<p class="disk-deferred-loader__hint">{$hint}</p>
		<p class="disk-deferred-loader__status" id="deferred-document-loader-status" role="status"></p>
		<button type="button" class="disk-deferred-loader__button" id="deferred-document-loader-open-now">
			{$openNow}
		</button>
		<button type="button" class="disk-deferred-loader__button" id="deferred-document-loader-cancel">
			{$cancel}
		</button>
	</main>
	<script>
		(() => {
			const LOAD_DELAY = 5000;
			const COUNTDOWN_TEXT = {$countdownText};
			const CANCELLED_TEXT = {$cancelledText};
			// A visible tab hands off at once, so the stub would only flash. It is revealed after this
			// delay, which leaves the slow hand-off with a loading state instead of a bare page.
			const REVEAL_DELAY = 300;

			const shortLink = {$shortLink};

			let isLoaded = false;
			let isCancelled = false;
			let loadTimeoutId = null;
			let revealTimeoutId = null;

			const postShortLinkContinuation = () => {
				const form = document.createElement('form');
				form.method = 'post';
				form.action = shortLink.url;
				Object.entries(shortLink.fields).forEach(([name, value]) => {
					const input = document.createElement('input');
					input.type = 'hidden';
					input.name = name;
					input.value = value;
					form.append(input);
				});
				document.body.append(form);
				form.submit();
			};

			const getImmediateLoadUrl = () => {
				const url = new URL(window.location.href);
				url.searchParams.set('immediate_load', 'Y');

				return url;
			};

			const cancelLoad = () => {
				if (loadTimeoutId !== null)
				{
					window.clearTimeout(loadTimeoutId);
					loadTimeoutId = null;
				}
			};

			const reveal = () => {
				if (revealTimeoutId !== null)
				{
					window.clearTimeout(revealTimeoutId);
					revealTimeoutId = null;
				}

				const stub = document.getElementById('deferred-document-loader');
				stub?.classList.add('disk-deferred-loader--shown');
			};

			const loadDocument = () => {
				if (isLoaded)
				{
					return;
				}

				isLoaded = true;
				cancelLoad();
				if (shortLink !== null)
				{
					postShortLinkContinuation();

					return;
				}

				window.location.replace(getImmediateLoadUrl().toString());
			};

			const setStatus = (text) => {
				const status = document.getElementById('deferred-document-loader-status');
				if (status)
				{
					status.textContent = String(text || '');
				}
			};

			const scheduleLoad = () => {
				if (isLoaded || isCancelled)
				{
					return;
				}

				cancelLoad();
				loadTimeoutId = window.setTimeout(loadDocument, LOAD_DELAY);
				// The region is emptied first, so a restarted countdown is announced again.
				setStatus('');
				setStatus(String(COUNTDOWN_TEXT || '').replace('#SECONDS#', String(LOAD_DELAY / 1000)));
			};

			const cancelCountdown = () => {
				if (isLoaded || isCancelled)
				{
					return;
				}

				isCancelled = true;
				cancelLoad();
				setStatus(CANCELLED_TEXT);
			};

			const onVisibilityChange = () => {
				if (document.visibilityState === 'visible')
				{
					scheduleLoad();

					return;
				}

				// A paused countdown must not keep announcing the opening; the cancel text stays.
				if (!isCancelled)
				{
					setStatus('');
				}

				cancelLoad();
			};

			const link = document.getElementById('deferred-document-loader-link');
			if (link instanceof HTMLAnchorElement)
			{
				link.href = shortLink === null ? getImmediateLoadUrl().toString() : shortLink.url;
				if (shortLink !== null)
				{
					link.addEventListener('click', (event) => {
						event.preventDefault();
						loadDocument();
					});
				}
			}

			// The buttons are live before the visible tab hands off, as the stub may be revealed meanwhile.
			document.getElementById('deferred-document-loader-open-now')?.addEventListener('click', loadDocument);
			document.getElementById('deferred-document-loader-cancel')?.addEventListener('click', cancelCountdown);
			document.addEventListener('keydown', (event) => {
				if (event.key === 'Escape' && loadTimeoutId !== null)
				{
					cancelCountdown();
				}
			});

			if (document.visibilityState === 'visible')
			{
				revealTimeoutId = window.setTimeout(reveal, REVEAL_DELAY);
				loadDocument();

				return;
			}

			reveal();
			document.addEventListener('visibilitychange', onVisibilityChange);
		})();
	</script>
</body>
</html>
HTML;
	}

	/**
	 * What the stub of a short opening posts back to its address: the code, a continuation in the checked mode
	 * and the mark of the immediate load, all in the body. A view asked of an editable source stays a view, or
	 * the stub would lead a refused edit back to editing; edit is asked only where the source allows it.
	 */
	private function getShortLinkContinuation(): string
	{
		if ($this->shortLinkOpening === null)
		{
			return 'null';
		}

		return Json::encode([
			'url' => $this->shortLinkOpening->getCanonicalUrl(),
			'fields' => [
				ShortLink::PARAM_CODE => $this->shortLinkOpening->getCode(),
				ShortLink::PARAM_OPERATION => ShortLink::OPERATION_CONTINUE,
				ShortLink::PARAM_REQUESTED_MODE => $this->accessLevel === UnifiedLinkAccessLevel::Edit
					&& $this->shortLinkOpening->allowsEditBySource()
					? ShortLink::MODE_EDIT
					: ShortLink::MODE_VIEW
				,
				'immediate_load' => 'Y',
			],
		], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
	}

	private function renderHint(): string
	{
		$hint = htmlspecialcharsbx(Loc::getMessage('DISK_DEFERRED_DOCUMENT_LOADER_HINT') ?? '');

		return str_replace(
			['[immediate_load_link]', '[/immediate_load_link]'],
			['<a class="disk-deferred-loader__link" id="deferred-document-loader-link">', '</a>'],
			$hint,
		);
	}

	public function getHeaders(): array
	{
		return [
			'Content-Type' => self::CONTENT_TYPE,
			'Cache-Control' => self::CACHE_CONTROL,
		];
	}
}
