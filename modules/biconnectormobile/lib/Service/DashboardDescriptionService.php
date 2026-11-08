<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Service;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Public\Provider\DashboardDetailInfoProvider;

/**
 * The description of a report as plain text for the sheet of the mobile screen. The source is the same
 * public provider the web "about the report" slider reads
 * (biconnector.apachesuperset.dashboard.detail.info/class.php): for a report of the portal it is BBCode
 * out of the repository, for a report of Bitrix24 or of Marketplace it is the description of the
 * Marketplace application, fetched over HTTP and cached for a day.
 *
 * That outbound request is why the description has an action of its own rather than a place in the state
 * of the screen: the state answer gates the mounting of the report frame and is re-asked by the poll,
 * and the description must not stand between the user and the report.
 */
final class DashboardDescriptionService
{
	// Markup encoded twice needs one pass per layer of encoding, and three of them cover anything a
	// description is written with. What is left after them stays encoded text, which no html context can
	// turn back into a tag.
	private const MAX_DECODE_PASSES = 3;

	// Stands in for a `<` of the text itself while the tags are being stripped. A control character is
	// used so that nothing a description is written with can be mistaken for it; an occurrence of it in
	// the source is dropped before the mark is placed.
	private const PLAIN_ANGLE_MARK = "\x02";

	public function getForDashboard(int $dashboardId): string
	{
		$viewStateService = new DashboardViewStateService();

		$dashboard = $viewStateService->findDashboard($dashboardId);
		if (!$dashboard || !$viewStateService->canView($dashboard))
		{
			// The refusal of the screen belongs to the state action; here a report the user may not view
			// simply has no description to show, so the header carries no icon.
			return '';
		}

		$description = (new DashboardDetailInfoProvider())->getByDashboard($dashboard)?->getDescription() ?? '';
		if ($description === '')
		{
			return '';
		}

		return $this->toPlainText($description, $dashboard->getType());
	}

	/**
	 * The description is kept as markup - BBCode for a report of the portal, HTML for an application of
	 * Marketplace - and the sheet shows plain text, so the markup is dropped instead of being rendered.
	 * The web converts the very same two shapes before printing them as HTML
	 * (ApacheSupersetDashboardDetailInfoComponent::prepareDescription).
	 */
	private function toPlainText(string $description, string $dashboardType): string
	{
		$text = $dashboardType === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM
			? (new \CTextParser())->convertText($description)
			: $description
		;

		// The entities are decoded before the tags are dropped, not after: CTextParser encodes the html of
		// a description of its own accord, and a decoding that comes last hands back the very tags the
		// strip has just removed. The pair repeats until the text stops changing, so that markup written
		// as entities does not outlive the pass that decodes it.
		for ($pass = 0; $pass < self::MAX_DECODE_PASSES; $pass++)
		{
			$plain = $this->dropMarkup(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($plain === $text)
			{
				break;
			}

			$text = $plain;
		}

		$text = str_replace("\r", '', $text);
		$text = preg_replace('#[ \t]+\n#', "\n", $text);
		$text = preg_replace('#\n{3,}#', "\n\n", $text);

		return trim($text);
	}

	/**
	 * The content of script and style leaves with the tag instead of staying behind as visible text, block
	 * markup becomes the line breaking it stands for - without it the paragraphs of a Marketplace
	 * description would run into one another - and the rest of the tags are dropped.
	 */
	private function dropMarkup(string $html): string
	{
		$html = str_replace(self::PLAIN_ANGLE_MARK, '', $html);
		$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
		$html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
		$html = preg_replace('#</(?:p|div|li|tr|h[1-6]|blockquote|pre)>#i', "\n", $html) ?? $html;

		// A `<` that opens no tag is prose of the user - "5<10 items" - and strip_tags takes it along with
		// everything up to the next `>` or the end of the text. It is carried past the strip under a mark
		// of its own instead. What a tag may begin with is left alone: a letter, a closing slash, and the
		// `!`/`?` of a comment or a processing instruction, all of which strip_tags does read as markup.
		$html = preg_replace('#<(?!/?[a-zA-Z]|[!?])#', self::PLAIN_ANGLE_MARK, $html) ?? $html;

		return str_replace(self::PLAIN_ANGLE_MARK, '<', strip_tags($html));
	}
}
