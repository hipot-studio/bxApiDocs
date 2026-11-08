<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity;

use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\FillRepeatSaleTipsPayload;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Item;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\RepeatSale\Segment\Entity\RepeatSaleSegment;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AIActivity;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Body\Logo;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

final class RepeatSale extends AIActivity
{
	private const HELPDESK_CODE_COPILOT_WARNING = '20412666';
	private const HELPDESK_CODE_REPEAT_SALE = '25376986';

	private const HISTORY_VISIBLE_COUNT = 2;
	private const HISTORY_LIMIT = 20;
	private const HISTORY_PRODUCT_NAMES_SHOWN = 2;
	private const HISTORY_PRODUCT_NAMES_LIMIT = 20;

	private ?RepeatSaleSegment $segment = null;
	private bool $segmentLoaded = false;

	protected function getActivityTypeId(): string
	{
		return 'RepeatSale';
	}

	public function getTitle(): string
	{
		return Loc::getMessage( 'CRM_TIMELINE_ITEM_REPEAT_SALE_TITLE');
	}

	public function getIconCode(): ?string
	{
		return Common\Icon::REPEAT_SALE;
	}

	public function getLogo(): ?Logo
	{
		// The redesign keeps the original repeat sale logo.
		return Common\Logo::getInstance(Common\Logo::REPEAT_SALE)->createLogo();
	}

	public function getContentBlocks(): array
	{
		$result = [];

		$contextType = $this->getContext()->getType();
		$jobResult = $this->getJobResult();
		$nothingToRecommend = self::isNothingToRecommend($jobResult, $this->getProviderParams());

		if (self::hasWebSurface($contextType))
		{
			$result['repeatSaleBody'] = $this->buildRedesignBodyBlock($jobResult, $nothingToRecommend);
		}

		if (self::hasMobileSurface($contextType))
		{
			$result += self::asMobileSurface([
				'description' => $this->buildDescriptionBlock($jobResult, $nothingToRecommend),
				'warning' => $this->buildWarningBlock($jobResult),
				'segment' => $this->buildSegmentBlock(),
			]);
		}

		$errorBlock = self::buildErrorBlock($jobResult);
		if (isset($errorBlock))
		{
			$result['error'] = $errorBlock;
		}

		return $result;
	}

	/**
	 * The redesigned body is a web-only surface: the mobile app renders the blocks it had before,
	 * built by the very same methods. A pull payload is rendered by both clients, so it carries both
	 * surfaces at once and each client keeps the blocks of its own scope.
	 */
	private static function hasWebSurface(string $contextType): bool
	{
		return $contextType !== Context::MOBILE;
	}

	private static function hasMobileSurface(string $contextType): bool
	{
		return $contextType === Context::MOBILE || $contextType === Context::PULL;
	}

	/**
	 * Blocks only the mobile app renders: each is scoped mobile, so the web layout drops them, and a
	 * builder that produced nothing is dropped here.
	 *
	 * @param array<string, ContentBlock|null> $blocks
	 * @return array<string, ContentBlock>
	 */
	private static function asMobileSurface(array $blocks): array
	{
		return array_map(
			static fn (ContentBlock $block): ContentBlock => $block->setScopeMobile(),
			array_filter($blocks),
		);
	}

	/**
	 * Redesigned RepeatSale card body (single custom block).
	 *
	 * Two independent sources: facts about purchases come from CRM deals, prose comes from the
	 * finished AI job payload, scenario data comes from the segment record.
	 */
	private function buildRedesignBodyBlock(?Result $jobResult, bool $nothingToRecommend): ContentBlock
	{
		$payload = $jobResult?->getPayload();
		$segment = $this->getSegment();

		$props = self::buildBodyProps(
			payload: $payload instanceof FillRepeatSaleTipsPayload ? $payload : null,
			segment: $segment,
			history: $this->buildHistoryFromDeals(),
			segmentAction: self::buildSegmentAction(
				$segment?->getId() ?? 0,
				Container::getInstance()->getRepeatSaleAvailabilityChecker()->hasPermission(),
			),
			activityDescription: $this->getActivityDescriptionText(),
			scenarioNote: $this->getScenarioNote(),
			nothingToRecommend: $nothingToRecommend,
		);

		return self::buildWebBodyBlock($props + $this->buildDeferredHistoryProps());
	}

	/**
	 * A pushed card carries no history (see buildHistoryFromDeals), so it says so and names the activity
	 * to ask about: the client loads the history in a request of its own, where the reader of the card and
	 * the user of the request are the same person. Everywhere else the history is already in the props.
	 *
	 * @return array{historyDeferred?: bool, activityId?: int}
	 */
	private function buildDeferredHistoryProps(): array
	{
		if ($this->getContext()->getType() !== Context::PULL)
		{
			return [];
		}

		$activityId = $this->getActivityId();

		return $activityId > 0 ? ['historyDeferred' => true, 'activityId' => $activityId] : [];
	}

	/**
	 * The block has a web renderer only, hence the scope: the mobile app filters it out instead of
	 * meeting a renderer name its block factory does not know.
	 */
	private static function buildWebBodyBlock(array $props): ContentBlock
	{
		return (new ContentBlock\RepeatSaleBody($props))->setScopeWeb();
	}

	/**
	 * Who may open the segment is decided here, not in the component - a permitted user
	 * gets the segment settings slider, everyone else gets the feature restriction promo. Without a
	 * segment there is nothing to open, so the pill gets no action at all.
	 */
	private static function buildSegmentAction(int $segmentId, bool $hasPermission): ?JsEvent
	{
		if ($segmentId <= 0)
		{
			return null;
		}

		if (!$hasPermission)
		{
			return new JsEvent('Activity:RepeatSale:ShowRestrictionSlider');
		}

		return (new JsEvent('Activity:RepeatSale:OpenSegment'))
			->addActionParamInt('segmentId', $segmentId)
		;
	}

	/**
	 * Props of the RepeatSaleBody component.
	 *
	 * The payload summary of past purchases (ordersOverview, lastPurchaseDate, lastPurchaseDetails)
	 * is deliberately not passed: the history table replaced it. A slot without data is omitted,
	 * so the component skips it instead of rendering a stub.
	 *
	 * A history row is
	 * array{prod: string, prodRest: string[], date: string, sum: string, url: string, isBase: bool}:
	 * prod is the visible part of the name and the only part that links to the deal, prodRest holds
	 * the remaining product names the row unfolds behind its counter, empty for a name that is not
	 * built from products. Both parts together stay within HISTORY_PRODUCT_NAMES_LIMIT names.
	 */
	private static function buildBodyProps(
		?FillRepeatSaleTipsPayload $payload,
		?RepeatSaleSegment $segment,
		array $history,
		?Action $segmentAction,
		string $activityDescription = '',
		array $scenarioNote = [],
		bool $nothingToRecommend = false,
	): array
	{
		$props = [
			'segmentName' => $segment?->getTitle()
				?? Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_UNKNOWN_SCENARIO'),
			'segmentId' => $segment?->getId() ?? 0,
			'visibleCount' => self::HISTORY_VISIBLE_COUNT,
			'history' => $history,
		];

		if ($segmentAction !== null)
		{
			$props['segmentAction'] = $segmentAction;
		}

		// The prompt reaches the card twice - as the fallback headline and as the chip hint - and is
		// flattened once here, so both slots carry the same text and stay comparable.
		$segmentPrompt = self::segmentPromptAsPlainText($segment);

		$plannedAction = self::getPlannedActionText($payload);
		$promptHeadline = $plannedAction === ''
			? self::getFallbackHeadlineText(
				$payload,
				$segmentPrompt,
				$activityDescription,
				$nothingToRecommend,
			)
			: '';

		$optionalProps = [
			// The chip hint is the segment prompt, so it is dropped for one reason only - it would
			// repeat the headline - and that happens exactly where the headline is that very prompt.
			'segmentDescription' => $promptHeadline === $segmentPrompt ? '' : $segmentPrompt,
			'action' => $plannedAction === '' ? $promptHeadline : $plannedAction,
			'reasoning' => self::getReasoningText($payload),
			'historyTotalHint' => self::getHistoryTotalHintText(count($history)),
		];

		foreach ($optionalProps as $name => $value)
		{
			$value = trim($value);
			if ($value !== '')
			{
				$props[$name] = $value;
			}
		}

		if ($scenarioNote !== [])
		{
			$props['scenarioNote'] = $scenarioNote;
		}

		return $props;
	}

	// Headline of the banner. The field mapping is fixed by the copilot response contract: the
	// recommended action is bestWayToContact and nothing else stands in for it.
	private static function getPlannedActionText(?FillRepeatSaleTipsPayload $payload): string
	{
		return trim((string)($payload?->actionPlan?->bestWayToContact ?? ''));
	}

	/**
	 * Headline standing in for a missing recommendation. A finished job that produced none leaves the
	 * segment prompt: prompts are written as an address to the manager and read as a headline as they
	 * are. With no job at all the activity description stands in instead - it may have been edited by
	 * hand and then it says more than the prompt. A finished job rewrites that description into its
	 * own formatted report ('What to do:', 'Purchase history:' and all), which must never reach the
	 * banner, so the description branch is bound to the absence of a payload.
	 *
	 * An answer with nothing to recommend needs neither stand-in: showing the prompt or the
	 * description there would read as an answer still on its way.
	 */
	private static function getFallbackHeadlineText(
		?FillRepeatSaleTipsPayload $payload,
		string $segmentPrompt,
		string $activityDescription,
		bool $nothingToRecommend = false
	): string
	{
		if ($nothingToRecommend)
		{
			return self::getNoTipsText();
		}

		if ($payload === null)
		{
			return trim($activityDescription);
		}

		return $segmentPrompt;
	}

	/**
	 * Two ways into the same state, and the card says the same thing about both: the finished job
	 * that found nothing to recommend, and the refusal that never queued a job because the markers
	 * carried too little about the client. A job answers for itself - the mark on the activity
	 * outlives the refusal that set it and must not speak for a job that ran later.
	 */
	private static function isNothingToRecommend(?Result $jobResult, array $providerParams): bool
	{
		if ($jobResult !== null)
		{
			$payload = $jobResult->getPayload();

			return $payload instanceof FillRepeatSaleTipsPayload && $payload->noRecommendation;
		}

		return !empty($providerParams[FillRepeatSaleTips::PROVIDER_PARAM_DATA_INSUFFICIENT]);
	}

	// One place for the phrase both surfaces show, so its wording is a lang file matter alone.
	private static function getNoTipsText(): string
	{
		return (string)Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_NO_TIPS');
	}

	// Body of the suggestion panel - salesOpportunity by the same contract. The remaining action
	// plan fields (service improvement suggestions and the model reasoning with itself) are not
	// rendered anywhere on the card.
	private static function getReasoningText(?FillRepeatSaleTipsPayload $payload): string
	{
		return trim((string)($payload?->actionPlan?->salesOpportunity ?? ''));
	}

	// The description carries the segment prompt until the copilot overwrites it with its own result.
	private function getActivityDescriptionText(): string
	{
		$model = $this->getAssociatedEntityModel();

		return self::asPlainText(
			(string)($model?->get('DESCRIPTION') ?? ''),
			(int)($model?->get('DESCRIPTION_TYPE') ?? \CCrmContentType::PlainText),
		);
	}

	// The segment editor writes the prompt as BBCode, and a prompt typed without markup goes through
	// the same flattening unharmed.
	private static function segmentPromptAsPlainText(?RepeatSaleSegment $segment): string
	{
		return self::asPlainText((string)($segment?->getPrompt() ?? ''), \CCrmContentType::BBCode);
	}

	// The component interpolates props as plain text, and both the activity description and the
	// segment prompt may be stored as markup - flattening is left to the activity itself.
	private static function asPlainText(string $text, int $contentType): string
	{
		if (trim($text) === '')
		{
			return '';
		}

		$fields = [
			'DESCRIPTION' => $text,
			'DESCRIPTION_TYPE' => $contentType,
		];
		\CCrmActivity::PrepareDescriptionFields($fields, ['ENABLE_HTML' => false, 'ENABLE_BBCODE' => false]);

		return trim((string)($fields['DESCRIPTION_RAW'] ?? ''));
	}

	// The selection is capped, so a full-looking table must say when it is not full.
	// Below the cap the history is complete and the hint stays empty.
	private static function getHistoryTotalHintText(int $rowsCount): string
	{
		if ($rowsCount < self::HISTORY_LIMIT)
		{
			return '';
		}

		return (string)Loc::getMessage(
			'CRM_TIMELINE_ITEM_REPEAT_SALE_HISTORY_LIMIT_HINT',
			['#COUNT#' => self::HISTORY_LIMIT],
		);
	}

	private function getScenarioNote(): array
	{
		$text = (string)Loc::getMessage(
			'CRM_TIMELINE_ITEM_REPEAT_SALE_SCENARIO_NOTE',
			['#COPILOT_NAME#' => AIManager::getCopilotName()],
		);

		return self::splitScenarioNote(
			$text,
			(string)$this->getLinkOnHelp(self::HELPDESK_CODE_REPEAT_SALE),
		);
	}

	/**
	 * @return array{textBefore: string, linkText: string, textAfter: string, href: string}
	 */
	private static function splitScenarioNote(string $text, string $href): array
	{
		$matches = [];
		if (preg_match('/^(.*?)\[helpdesklink](.*?)\[\/helpdesklink](.*)$/su', $text, $matches) !== 1)
		{
			return [
				'textBefore' => $text,
				'linkText' => '',
				'textAfter' => '',
				'href' => '',
			];
		}

		return [
			'textBefore' => $matches[1],
			'linkText' => $matches[2],
			'textAfter' => $matches[3],
			'href' => $href,
		];
	}

	/**
	 * Purchase-history rows - the client's real WON deals from CRM (the same source the
	 * copilot receives as input via DataCollectorManager), not synthesized. Lightweight
	 * render-time read, isolated to this card, and limited to the deals the viewer may read.
	 */
	private function buildHistoryFromDeals(): array
	{
		// A pull payload is assembled once for the whole channel of the deal, while the history is
		// filtered by the permissions of one user - whoever changed the deal. Putting it there would
		// hand every subscriber the client's other deals they may not read, so the pushed card carries
		// no history and the next full render of the timeline brings it back.
		if ($this->getContext()->getType() === Context::PULL)
		{
			return [];
		}

		if ($this->getContext()->getEntityTypeId() !== \CCrmOwnerType::Deal)
		{
			return [];
		}

		$dealId = $this->getContext()->getEntityId();
		$dealFactory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
		if ($dealId <= 0 || $dealFactory === null)
		{
			return [];
		}

		$clientFilter = $this->resolveClientDealsFilter($dealFactory, $dealId);
		if ($clientFilter === null)
		{
			return [];
		}

		$items = $this->withBaseDeal(
			$this->loadReadableClientDeals($dealFactory, $clientFilter, $dealId),
			$dealFactory,
			$clientFilter,
			$this->getBaseDealId(),
		);

		$router = Container::getInstance()->getRouter();
		$productNames = self::loadProductNames(
			array_map(static fn (Item $item): int => $item->getId(), $items)
		);

		// Every deal of the selection becomes a row linking to it - product names included, because
		// the selection already holds nothing the viewer may not read.
		$rows = [];
		foreach ($items as $item)
		{
			$url = $router->getItemDetailUrl(\CCrmOwnerType::Deal, $item->getId());
			$rows[$item->getId()] = self::historyRowName(
				$productNames[$item->getId()] ?? [],
				(string)$item->get(Item::FIELD_NAME_TITLE),
			) + [
				'date' => $this->formatPurchaseDate($item->get(Item::FIELD_NAME_CREATED_TIME)),
				'sum' => $this->historySum($item),
				'url' => $url ? (string)$url : '',
			];
		}

		return self::markBaseHistoryRow($rows, $this->getBaseDealId());
	}

	/**
	 * Won deals of the client the viewer is allowed to read. Access to the repeat sale deal says
	 * nothing about access to the client's other deals - those may belong to other managers and to
	 * other funnels - so the read goes through the permission-aware factory method.
	 *
	 * Permissions are applied by the query itself, before LIMIT, so HISTORY_LIMIT caps the rows the
	 * card shows and is not spent on deals that would be dropped anyway. A viewer with narrow rights
	 * legitimately gets a shorter history, down to none at all.
	 *
	 * The viewer is the user of the timeline context, passed explicitly: left to the factory default
	 * the read goes by the current user of the environment, and the card is built outside the
	 * viewer's own request too - a pull payload is assembled by whoever changed the deal.
	 *
	 * @return Item[]
	 */
	private function loadReadableClientDeals(
		\Bitrix\Crm\Service\Factory $dealFactory,
		array $clientFilter,
		int $dealId
	): array
	{
		return $dealFactory->getItemsFilteredByPermissions([
			'select' => self::historySelect(),
			// Won deals only, so the scenario's own repeat deals (still in progress) stay out.
			// The current deal is excluded by id, recurring templates by IS_RECURRING.
			'filter' => array_merge($clientFilter, [
				'=' . Item::FIELD_NAME_STAGE_SEMANTIC_ID => PhaseSemantics::SUCCESS,
				'=' . Item::FIELD_NAME_IS_RECURRING => 'N',
				'!=' . Item::FIELD_NAME_ID => $dealId,
			]),
			// History date is when the client ordered, so the deal creation date, not the close date.
			'order' => [Item::FIELD_NAME_CREATED_TIME => 'DESC'],
			'limit' => self::HISTORY_LIMIT,
		], $this->getContext()->getUserId());
	}

	/**
	 * The base purchase belongs in the history: it is the purchase the repeat sale was built on, and the
	 * card marks it. The plain selection takes the newest deals, so a client who bought more than
	 * HISTORY_LIMIT times since would push the base purchase out - it is read on its own and takes the
	 * place of the oldest row, keeping the total at HISTORY_LIMIT.
	 *
	 * @param Item[] $items
	 * @return Item[]
	 */
	private function withBaseDeal(
		array $items,
		\Bitrix\Crm\Service\Factory $dealFactory,
		array $clientFilter,
		int $baseDealId
	): array
	{
		if ($baseDealId <= 0)
		{
			return $items;
		}

		foreach ($items as $item)
		{
			if ($item->getId() === $baseDealId)
			{
				return $items;
			}
		}

		$baseItem = $this->loadReadableBaseDeal($dealFactory, $clientFilter, $baseDealId);
		if ($baseItem === null)
		{
			return $items;
		}

		if (count($items) >= self::HISTORY_LIMIT)
		{
			array_splice($items, self::HISTORY_LIMIT - 1);
		}

		// The rows stay ordered by purchase date, and the base purchase is older than the selection it
		// was missing from.
		$items[] = $baseItem;

		return $items;
	}

	/**
	 * The base purchase read with the same conditions as the selection, so a deal the viewer may not
	 * read, a lost one or a recurring template does not slip into the history through this path.
	 */
	private function loadReadableBaseDeal(
		\Bitrix\Crm\Service\Factory $dealFactory,
		array $clientFilter,
		int $baseDealId
	): ?Item
	{
		$items = $dealFactory->getItemsFilteredByPermissions([
			'select' => self::historySelect(),
			'filter' => array_merge($clientFilter, [
				'=' . Item::FIELD_NAME_STAGE_SEMANTIC_ID => PhaseSemantics::SUCCESS,
				'=' . Item::FIELD_NAME_IS_RECURRING => 'N',
				'=' . Item::FIELD_NAME_ID => $baseDealId,
			]),
			'limit' => 1,
		], $this->getContext()->getUserId());

		return $items[0] ?? null;
	}

	/**
	 * Only the fields the card shows. Product rows are not requested here at all: the whole
	 * PRODUCT_ROWS field makes Factory::prepareSelect() join IBLOCK_ELEMENT and
	 * PRODUCT_ROW_RESERVATION, and even a narrow one loads every position of every deal.
	 *
	 * @return string[]
	 */
	private static function historySelect(): array
	{
		return [
			Item::FIELD_NAME_ID,
			Item::FIELD_NAME_TITLE,
			Item::FIELD_NAME_OPPORTUNITY,
			Item::FIELD_NAME_CURRENCY_ID,
			Item::FIELD_NAME_CREATED_TIME,
		];
	}

	/**
	 * ALG-01: isBase is true only for the row of the deal the activity was created for. A base deal
	 * missing from the selection marks nothing. Rows keep their chronological order, and the deal
	 * identifier stays server-side - the client contract carries the flag only.
	 *
	 * @param array $rows history rows indexed by deal id
	 */
	private static function markBaseHistoryRow(array $rows, int $baseDealId): array
	{
		$history = [];
		foreach ($rows as $dealId => $row)
		{
			$row['isBase'] = $baseDealId > 0 && (int)$dealId === $baseDealId;
			$history[] = $row;
		}

		return $history;
	}

	/**
	 * The deal this activity was created for - the purchase that triggered the repeat sale.
	 * Zero when the base entity is not a deal, so no row gets marked.
	 *
	 * @see \Bitrix\Crm\RepeatSale\Service\Action\CreateActivityAction
	 */
	private function getBaseDealId(): int
	{
		$params = $this->getProviderParams();
		if ((int)($params['BASE_ENTITY_TYPE_ID'] ?? 0) !== \CCrmOwnerType::Deal)
		{
			return 0;
		}

		return max(0, (int)($params['BASE_ENTITY_ID'] ?? 0));
	}

	/**
	 * Name column of a history row: what the deal contains, then what it is called, then a neutral
	 * phrase. Only a name built from products has a rest to unfold - a deal title is shown whole.
	 *
	 * A deal without product rows shows its title as it is, auto generated titles and phone numbers
	 * included: the card is read by the person responsible for the deal, who already has access to
	 * both the deal and the client's phone.
	 *
	 * @param string[] $productNames unique product names of the deal, capped by the caller
	 * @return array{prod: string, prodRest: string[]}
	 */
	private static function historyRowName(array $productNames, string $dealTitle): array
	{
		if ($productNames !== [])
		{
			return [
				'prod' => implode(', ', array_slice($productNames, 0, self::HISTORY_PRODUCT_NAMES_SHOWN)),
				'prodRest' => array_slice($productNames, self::HISTORY_PRODUCT_NAMES_SHOWN),
			];
		}

		$title = trim($dealTitle);

		return [
			'prod' => $title !== ''
				? $title
				: (string)Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_HISTORY_DEFAULT_PRODUCT'),
			'prodRest' => [],
		];
	}

	/**
	 * Product names of the given deals, first position of the order first. The card shows names and
	 * counts names, so identical positions are collapsed by the query and never reach PHP.
	 *
	 * @param int[] $dealIds
	 * @return array<int, string[]> names indexed by deal id, HISTORY_PRODUCT_NAMES_LIMIT at most
	 */
	private static function loadProductNames(array $dealIds): array
	{
		$query = self::productNamesQuery($dealIds);

		return $query === null ? [] : self::groupUniqueProductNames($query->exec());
	}

	/**
	 * @param int[] $dealIds
	 * @return int[]
	 */
	private static function uniqueDealIds(array $dealIds): array
	{
		$ids = array_filter(array_map('intval', $dealIds), static fn (int $dealId): bool => $dealId > 0);

		return array_values(array_unique($ids));
	}

	/**
	 * Names of the whole selection in one read, each deal capped on its own: every deal is a part of the
	 * UNION with a LIMIT of its own, so a deal of hundreds of positions neither pulls all of them nor
	 * eats the share of the other deals of the card. The selection is bounded by HISTORY_LIMIT, so the
	 * number of parts is bounded too. What the parts themselves cut is repetition: identical positions
	 * are collapsed into one row.
	 *
	 * Null when the selection names no deal to read.
	 *
	 * @param int[] $dealIds
	 */
	private static function productNamesQuery(array $dealIds): ?Query
	{
		$parts = array_map(
			static fn (int $dealId): Query => self::dealProductNamesQuery($dealId),
			self::uniqueDealIds($dealIds),
		);

		$query = array_shift($parts);
		foreach ($parts as $part)
		{
			$query->unionAll($part);
		}

		return $query;
	}

	// Distinct names of one deal, first position of the order first, HISTORY_PRODUCT_NAMES_LIMIT of them.
	private static function dealProductNamesQuery(int $dealId): Query
	{
		return ProductRowTable::query()
			->registerRuntimeField(new ExpressionField('FIRST_ROW_ID', 'MIN(%s)', ['ID']))
			->setSelect(['OWNER_ID', 'PRODUCT_NAME', 'FIRST_ROW_ID'])
			->where('OWNER_TYPE', \CCrmOwnerTypeAbbr::Deal)
			->where('OWNER_ID', $dealId)
			->setGroup(['OWNER_ID', 'PRODUCT_NAME'])
			->setOrder(['FIRST_ROW_ID' => 'ASC'])
			->setLimit(self::HISTORY_PRODUCT_NAMES_LIMIT)
		;
	}

	/**
	 * Names grouped by deal, first appearance kept. A name is shown as the deal stores it, so the
	 * card and the deal itself never disagree about what was bought. Identical positions are already
	 * collapsed by the query; the check below catches the ones it kept apart by surrounding spaces
	 * only, and two positions that really differ stay two names.
	 *
	 * A row of an order of hundreds of positions enumerates the first HISTORY_PRODUCT_NAMES_LIMIT of
	 * them and the deal behind the row link holds the whole composition. The cap is applied while the
	 * fetched rows are walked over, so a name past it takes no place in the payload of the card.
	 *
	 * @param iterable $rows raw OWNER_ID / PRODUCT_NAME pairs
	 * @return array<int, string[]> names indexed by deal id, HISTORY_PRODUCT_NAMES_LIMIT at most
	 */
	private static function groupUniqueProductNames(iterable $rows): array
	{
		$names = [];
		foreach ($rows as $row)
		{
			$dealId = (int)($row['OWNER_ID'] ?? 0);
			if ($dealId <= 0 || count($names[$dealId] ?? []) >= self::HISTORY_PRODUCT_NAMES_LIMIT)
			{
				continue;
			}

			$name = trim((string)($row['PRODUCT_NAME'] ?? ''));
			if ($name === '' || in_array($name, $names[$dealId] ?? [], true))
			{
				continue;
			}

			$names[$dealId][] = $name;
		}

		return $names;
	}

	// Row sum is the deal OPPORTUNITY. Unfilled renders as a dash, not as zero money: for some
	// deals the amount lives only in a call transcript, with no structured source behind it.
	private function historySum(Item $item): string
	{
		$opportunity = (float)$item->get(Item::FIELD_NAME_OPPORTUNITY);
		if ($opportunity <= 0)
		{
			return (string)Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_HISTORY_EMPTY_SUM');
		}

		return html_entity_decode(
			\CCrmCurrency::MoneyToString($opportunity, (string)$item->get(Item::FIELD_NAME_CURRENCY_ID)),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);
	}

	/**
	 * Filter selecting the client's other deals - by company (preferred) or primary contact.
	 * Null when no client is known at all.
	 *
	 * The client of the activity comes first: the recommendation was written about that very company or
	 * contact, and the client of the deal may have been changed since - the table would then speak about
	 * someone the prose does not. Activities created before these parameters existed have the client of
	 * the deal as their only source.
	 */
	private function resolveClientDealsFilter(\Bitrix\Crm\Service\Factory $dealFactory, int $dealId): ?array
	{
		return self::clientDealsFilterFromParams($this->getProviderParams())
			?? self::clientDealsFilterFromDeal($dealFactory, $dealId);
	}

	/**
	 * Client the activity was created for. It is a company or a contact - the base purchase lives in its
	 * own pair of parameters and is a deal, no client filter can be built from it.
	 *
	 * @see \Bitrix\Crm\RepeatSale\Service\Action\CreateActivityAction
	 */
	private static function clientDealsFilterFromParams(array $params): ?array
	{
		$clientId = (int)($params['CLIENT_ENTITY_ID'] ?? 0);
		if ($clientId <= 0)
		{
			return null;
		}

		return match ((int)($params['CLIENT_ENTITY_TYPE_ID'] ?? 0))
		{
			\CCrmOwnerType::Company => self::companyDealsFilter($clientId),
			\CCrmOwnerType::Contact => self::contactDealsFilter($clientId),
			default => null,
		};
	}

	private static function clientDealsFilterFromDeal(
		\Bitrix\Crm\Service\Factory $dealFactory,
		int $dealId
	): ?array
	{
		$deal = $dealFactory->getItem($dealId, [
			Item::FIELD_NAME_COMPANY_ID,
			Item::FIELD_NAME_CONTACT_BINDINGS,
		]);
		if ($deal === null)
		{
			return null;
		}

		$companyId = (int)$deal->get(Item::FIELD_NAME_COMPANY_ID);
		if ($companyId > 0)
		{
			return self::companyDealsFilter($companyId);
		}

		foreach ((array)$deal->get(Item::FIELD_NAME_CONTACT_BINDINGS) as $binding)
		{
			$contactId = is_array($binding)
				? (int)($binding['CONTACT_ID'] ?? 0)
				: (int)(is_object($binding) && method_exists($binding, 'getContactId') ? $binding->getContactId() : 0);
			if ($contactId > 0)
			{
				return self::contactDealsFilter($contactId);
			}
		}

		return null;
	}

	private static function companyDealsFilter(int $companyId): array
	{
		return ['@' . Item::FIELD_NAME_COMPANY_ID => [$companyId]];
	}

	private static function contactDealsFilter(int $contactId): array
	{
		return ['@' . Item::FIELD_NAME_CONTACT_BINDINGS . '.CONTACT_ID' => [$contactId]];
	}

	// Month and year of the purchase, month name taken from the current locale ('f' - nominative).
	private function formatPurchaseDate($date): string
	{
		if (!($date instanceof \Bitrix\Main\Type\Date))
		{
			return '';
		}

		return (string)FormatDate('f Y', $this->inViewerTime($date));
	}

	/**
	 * The month is formatted from the timestamp of the object as it is stored, and deals are stored in
	 * server time: a purchase made close to the turn of a month would show the neighbouring month to a
	 * viewer in another timezone. The offset is the one of the user the card is built for - the same viewer
	 * the history read goes by - and it is taken for the date being converted, because a purchase of a past
	 * year may fall on a different offset than the present moment.
	 */
	private function inViewerTime(\Bitrix\Main\Type\Date $date): \Bitrix\Main\Type\Date
	{
		if (!($date instanceof \Bitrix\Main\Type\DateTime))
		{
			return $date;
		}

		$offset = \CTimeZone::GetOffset($this->getContext()->getUserId(), false, $date);

		return $offset === 0 ? $date : (clone $date)->add($offset . ' seconds');
	}

	public function getButtons(): array
	{
		$scheduleButtonType = $this->isScheduled() ? Button::TYPE_PRIMARY : Button::TYPE_SECONDARY;
		$payload = $this->getJobResult()?->getPayload();
		$description = self::getScheduleDescription(
			$payload instanceof FillRepeatSaleTipsPayload ? $payload : null,
			(string)($this->getAssociatedEntityModel()?->get('SUBJECT') ?? ''),
		);

		$scheduleButton = $this->getScheduleButton(
			'Activity:RepeatSale:Schedule',
			$description,
			$scheduleButtonType
		);

		return array_merge(['scheduleButton' => $scheduleButton], $this->getAIButtons());
	}

	private static function getScheduleDescription(
		?FillRepeatSaleTipsPayload $payload,
		string $activitySubject,
	): string
	{
		$recommendation = self::getPlannedActionText($payload);

		return $recommendation !== '' ? $recommendation : trim($activitySubject);
	}

	public function getMenuItems(): array
	{
		$menuItems = parent::getMenuItems();
		unset($menuItems['view']);

		if (!$this->hasUpdatePermission())
		{
			unset($menuItems['delete']);
		}

		return $menuItems;
	}

	protected function getScenarios(): array
	{
		return [
			Scenario::REPEAT_SALE_TIPS_SCENARIO,
		];
	}

	protected function canShowAIActions(): bool
	{
		return $this->getSegment() !== null;
	}

	// region Build content blocks
	private function buildDescriptionBlock(?Result $jobResult, bool $nothingToRecommend): ?ContentBlock
	{
		// base block
		$block = (new ContentBlock\EditableDescription())
			->setEditable(false)
			->setUseBBCodeEditor(true)
			->setBackgroundColor(
				$this->isScheduled()
					? ContentBlock\EditableDescription::BG_COLOR_YELLOW
					: ContentBlock\EditableDescription::BG_COLOR_WHITE
			)
			->setHeight(ContentBlock\EditableDescription::HEIGHT_AUTO)
		;

		if ($nothingToRecommend)
		{
			return self::buildNoTipsDescriptionBlock($block, $jobResult !== null);
		}

		if (
			is_null($jobResult)
			|| !$jobResult->isSuccess()
		) // not processed by copilot or error
		{
			$description = $this->getSegment()?->getPrompt() ?? '';
			$headerText = $this->getDescriptionHeaderText('CRM_TIMELINE_ITEM_REPEAT_SALE_HEADER_TEXT_DEFAULT');

			return empty($description)
				? null // not found segment or empty description
				: $block
					->setCopied(true)
					->setHeaderText($headerText)
					->setText($description)
			;
		}

		// copilot in progress
		if ($jobResult->isPending())
		{
			return $block->setCopilotStatus(ContentBlock\EditableDescription::AI_IN_PROGRESS);
		}

		// copilot return success result
		if ($jobResult->isSuccess())
		{
			$headerText = $this->getDescriptionHeaderText('CRM_TIMELINE_ITEM_REPEAT_SALE_HEADER_TEXT_COPILOT');

			return $block
				->setCopied(true)
				->setHeaderText($headerText)
				->setText($this->getDescription())
				->setCopilotStatus(ContentBlock\EditableDescription::AI_SUCCESS)
			;
		}

		// copilot return error
		return $block
			->setText(Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_COPILOT_ERROR'))
			->setCopilotStatus(ContentBlock\EditableDescription::AI_NONE)
		;
	}

	/**
	 * Both headers the block otherwise carries describe this state wrongly - one invites the user to
	 * press the copilot, the other announces recommendations that were prepared - so the phrase stands
	 * alone. A job did run whenever there is a result behind the activity; without one the refusal
	 * happened before the queue and there is no copilot result to report.
	 */
	private static function buildNoTipsDescriptionBlock(
		ContentBlock\EditableDescription $block,
		bool $hasJobResult
	): ContentBlock\EditableDescription
	{
		return $block
			->setText(self::getNoTipsText())
			->setCopilotStatus(
				$hasJobResult
					? ContentBlock\EditableDescription::AI_SUCCESS
					: ContentBlock\EditableDescription::AI_NONE
			)
		;
	}

	private function buildWarningBlock(?Result $jobResult): ?ContentBlock
	{
		if ($jobResult?->isSuccess())
		{
			return ContentBlock\ContentBlockFactory::createFromHtmlString(
				(string)$this->getWarningText(),
				'copilot_warning_',
				[
					'link' => [
						'color' => ContentBlock\Text::COLOR_BASE_50,
						'size' => ContentBlock\Text::FONT_SIZE_SM,
						'decoration' => ContentBlock\Text::DECORATION_UNDERLINE,
					],
					'text' => [
						'color' => ContentBlock\Text::COLOR_BASE_50,
						'size' => ContentBlock\Text::FONT_SIZE_SM,
					],
				],
				''
			);
		}

		return null;
	}

	private function buildSegmentBlock(): ContentBlock
	{
		$segment = $this->getSegment();

		return self::buildSegmentLine(
			$segment,
			self::buildSegmentAction(
				$segment?->getId() ?? 0,
				Container::getInstance()->getRepeatSaleAvailabilityChecker()->hasPermission(),
			),
		);
	}

	// Scenario line of the mobile card: the segment name, a link when there is something to open.
	private static function buildSegmentLine(?RepeatSaleSegment $segment, ?Action $action): ContentBlock
	{
		$segmentId = $segment?->getId() ?? 0;
		$segmentName = $segment?->getTitle()
			?? (string)Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_UNKNOWN_SCENARIO');

		$textOrLink = ContentBlock\ContentBlockFactory::createTextOrLink($segmentName, $action);

		return (new ContentBlock\LineOfTextBlocks())
			->addContentBlock(
				'title',
				ContentBlock\ContentBlockFactory::createTitle(
					(string)Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_SCENARIO')
				)
			)
			->addContentBlock('value', $textOrLink->setIsBold($segmentId > 0))
		;
	}

	private static function buildErrorBlock(?Result $jobResult): ?ContentBlock
	{
		if (isset($jobResult) && !$jobResult->isSuccess())
		{
			return (new ContentBlock\ErrorBlock())
				->setTitle(Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_COPILOT_ERROR_TITLE'))
				->setDescription(Loc::getMessage('CRM_TIMELINE_ITEM_REPEAT_SALE_COPILOT_ERROR'))
				->setClosable(true)
				->setType(ContentBlock\ErrorBlock::ERROR_TYPE_AI)
			;
		}

		return null;
	}
	// endregion

	// region Internal utils
	private function getJobResult(): ?Result
	{
		return $this->getAIService()->getAIJobResult(FillRepeatSaleTips::TYPE_ID);
	}

	private function getProviderParams(): array
	{
		return (array)($this->getAssociatedEntityModel()?->get('PROVIDER_PARAMS') ?? []);
	}

	private function getSegment(): ?RepeatSaleSegment
	{
		if (!$this->segmentLoaded)
		{
			$segmentId = (int)($this->getProviderParams()['SEGMENT_ID'] ?? 0);

			$this->segment = RepeatSaleSegmentController::getInstance()->getById($segmentId);
			$this->segmentLoaded = true;
		}

		return $this->segment;
	}

	private function getDescription(): string
	{
		$description = (string)($this->getAssociatedEntityModel()?->get('DESCRIPTION') ?? '');

		// Temporarily removes [p] for mobile compatibility
		$descriptionType = (int)$this->getAssociatedEntityModel()?->get('DESCRIPTION_TYPE');
		if (
			$descriptionType === \CCrmContentType::BBCode
			&& $this->getContext()->getType() === Context::MOBILE
		)
		{
			$description = TextHelper::removeParagraphs($description);
		}

		return $description;
	}

	private function getDescriptionHeaderText(string $code): ?string
	{
		return Loc::getMessage(
			$code,
			[
				'[helpdesklink]' => '<a href="'
					. $this->getLinkOnHelp(self::HELPDESK_CODE_REPEAT_SALE)
					. '" target="blank">',
				'[/helpdesklink]' => '</a>',
				'#COPILOT_NAME#' => AIManager::getCopilotName(),
			],
		);
	}

	private function getWarningText(): ?string
	{
		return Loc::getMessage(
			'CRM_TIMELINE_ITEM_REPEAT_SALE_COPILOT_WARNING',
			[
				'[helpdesklink]' => '<a href="'
					. $this->getLinkOnHelp(self::HELPDESK_CODE_COPILOT_WARNING)
					. '" target="blank">',
				'[/helpdesklink]' => '</a>',
				'#COPILOT_NAME#' => AIManager::getCopilotName(),
			],
		);
	}
	// endregion
}
