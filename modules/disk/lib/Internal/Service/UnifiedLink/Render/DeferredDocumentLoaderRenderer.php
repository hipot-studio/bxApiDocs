<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

final class DeferredDocumentLoaderRenderer
{
	public const CONTENT_TYPE = 'text/html; charset=UTF-8';
	public const CACHE_CONTROL = 'no-store';

	public function render(): string
	{
		$languageId = htmlspecialcharsbx(Loc::getCurrentLang());
		$title = htmlspecialcharsbx(Loc::getMessage('DISK_DEFERRED_DOCUMENT_LOADER_TITLE') ?? '');
		$hint = $this->renderHint();

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
		@keyframes disk-deferred-loader-spin { to { transform: rotate(360deg); } }
		@media (prefers-reduced-motion: reduce) { .disk-deferred-loader__spinner { animation: none; } }
	</style>
</head>
<body>
	<main class="disk-deferred-loader" id="deferred-document-loader">
		<div class="disk-deferred-loader__spinner" aria-hidden="true"></div>
		<h1 class="disk-deferred-loader__title">{$title}</h1>
		<p class="disk-deferred-loader__hint">{$hint}</p>
	</main>
	<script>
		(() => {
			const LOAD_DELAY = 1000;
			// A visible tab hands off at once, so the stub would only flash. It is revealed after this
			// delay, which leaves the slow hand-off with a loading state instead of a bare page.
			const REVEAL_DELAY = 300;

			let isLoaded = false;
			let loadTimeoutId = null;
			let revealTimeoutId = null;

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
				window.location.replace(getImmediateLoadUrl().toString());
			};

			const scheduleLoad = () => {
				cancelLoad();
				loadTimeoutId = window.setTimeout(loadDocument, LOAD_DELAY);
			};

			const onVisibilityChange = () => {
				if (document.visibilityState === 'visible')
				{
					scheduleLoad();

					return;
				}

				cancelLoad();
			};

			const link = document.getElementById('deferred-document-loader-link');
			if (link instanceof HTMLAnchorElement)
			{
				link.href = getImmediateLoadUrl().toString();
			}

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
