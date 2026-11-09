<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Row\Assembler;

use Bitrix\BIConnector\Internal\Services\Scope\CompanyStructureNameProvider;
use Bitrix\BIConnector\Internal\Services\Scope\ScopeValueNameProvider;
use Bitrix\BIConnector\Internal\Services\Share\ShareStatusResolver;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DateFieldAssembler;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\ScreenReaderText;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

/**
 * Registry cells. Raw row keys: ID, DASHBOARD_ID, DASHBOARD_TITLE, AUTHOR_ID, STATUS, DATE_EXPIRE,
 * DATE_CREATE, DATE_LAST_OPENED, DISABLED_BY_ID, DATE_DISABLED, EXTERNAL_FILTER_VALUES,
 * URL_PARAMETER_VALUES, IS_DASHBOARD_BANNED, CAN_VIEW_DASHBOARD, DETAIL_URL.
 */
final class ShareRegistryRowAssembler extends RowAssembler
{
	private const GRID_MANAGER = 'BX.BIConnector.ShareRegistry.GridManager.Instance';
	private const AVATAR_SIZE = 100;
	private const DEFAULT_PROFILE_PATH = '/company/personal/user/#USER_ID#/';

	private const FILTER_TYPE_COMPANY_STRUCTURE = 'filter_company_structure';

	private const FILTER_TYPE_PARAMETERS = [
		'filter_tasks_flow' => Parameter::TasksFlowsFlowId,
		'filter_bp_workflow_template' => Parameter::WorkflowTemplateId,
	];

	/**
	 * A working link is green, a link needing the controller's attention is orange,
	 * links that ended are grey.
	 */
	private const STATUS_LABEL_COLORS = [
		ShareStatusResolver::STATUS_ACTIVE => 'success',
		ShareStatusResolver::STATUS_NO_RIGHT => 'warning',
		ShareStatusResolver::STATUS_DISABLED => 'light',
		ShareStatusResolver::STATUS_EXPIRED => 'light',
	];

	private const FILTER_TYPE_TITLE_CODES = [
		self::FILTER_TYPE_COMPANY_STRUCTURE => 'COMPANY_STRUCTURE',
		'filter_tasks_flow' => 'TASKS_FLOW',
		'filter_bp_workflow_template' => 'WORKFLOW_TEMPLATE',
	];

	/** @var array<int, string> Not escaped. */
	private array $userNames = [];

	/** @var array<int, string> user id => avatar url */
	private array $userAvatars = [];

	/** @var array<string, array<int, string>> parameter code => id => name */
	private array $scopeNames = [];

	/** @var array<string, string> company structure value => name, not escaped */
	private array $structureNames = [];

	private readonly ScopeValueNameProvider $scopeValueNameProvider;
	private readonly CompanyStructureNameProvider $structureNameProvider;

	/**
	 * @param int|null $filteredAuthorId The only author the grid is filtered by.
	 */
	public function __construct(
		array $visibleColumnIds,
		?ScopeValueNameProvider $scopeValueNameProvider = null,
		private readonly ?int $filteredAuthorId = null,
		?CompanyStructureNameProvider $structureNameProvider = null,
	)
	{
		parent::__construct($visibleColumnIds);
		$this->scopeValueNameProvider = $scopeValueNameProvider ?? new ScopeValueNameProvider();
		$this->structureNameProvider = $structureNameProvider ?? new CompanyStructureNameProvider();
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new DateFieldAssembler(
				['DATE_EXPIRE', 'DATE_CREATE', 'DATE_LAST_OPENED', 'DATE_DISABLED'],
				timeFormat: DateFieldAssembler::TIME_FORMAT_SHORT,
			),
		];
	}

	public function prepareRows(array $rowsList): array
	{
		$rowsList = parent::prepareRows($rowsList);
		$this->loadUserNames($rowsList);

		$visibleColumns = array_flip($this->getVisibleColumnIds());
		if (isset($visibleColumns['SLICE']))
		{
			$this->loadScopeNames($rowsList);
		}
		foreach ($rowsList as &$row)
		{
			$data = $row['data'] ?? [];
			$row['columns'] ??= [];

			$cells = [
				'DASHBOARD' => fn() => $this->renderDashboard($data),
				'AUTHOR' => fn() => $this->renderAuthor((int)($data['AUTHOR_ID'] ?? 0)),
				'STATUS' => fn() => $this->renderStatus((string)($data['STATUS'] ?? '')),
				'SLICE' => fn() => $this->renderSlice($data),
				'DATE_LAST_OPENED' => fn() => $this->renderLastOpened($data, $row['columns']['DATE_LAST_OPENED'] ?? null),
				'DISABLED_BY' => fn() => $this->renderDisabledBy($data),
			];

			foreach ($cells as $columnId => $render)
			{
				if (isset($visibleColumns[$columnId]))
				{
					$row['columns'][$columnId] = $render();
				}
			}
		}
		unset($row);

		return $rowsList;
	}

	private function renderDashboard(array $data): string
	{
		$title = htmlspecialcharsbx((string)($data['DASHBOARD_TITLE'] ?? ''));
		$url = (string)($data['DETAIL_URL'] ?? '');
		if (empty($data['CAN_VIEW_DASHBOARD']) || $url === '')
		{
			return $title;
		}

		$onclick = htmlspecialcharsbx(self::GRID_MANAGER . ".openDashboard('" . \CUtil::JSEscape($url) . "'); return false;");
		$href = htmlspecialcharsbx($url);

		return "<a href=\"{$href}\" onclick=\"{$onclick}\">{$title}</a>";
	}

	/**
	 * A chip that toggles the author filter: set to this author only, or removed when already set so.
	 */
	private function renderAuthor(int $authorId): string
	{
		$name = $this->userNames[$authorId] ?? '';
		if ($authorId <= 0 || $name === '')
		{
			return htmlspecialcharsbx($name);
		}

		$isActive = $authorId === $this->filteredAuthorId;
		$onclick = htmlspecialcharsbx(
			self::GRID_MANAGER . '.toggleAuthorFilter('
			. $authorId . ", '" . \CUtil::JSEscape($name) . "', " . ($isActive ? 'true' : 'false')
			. '); return false;'
		);

		$class = 'biconnector-share-registry-author' . ($isActive ? ' --active' : '');

		return '<button type="button"'
			. ' class="' . $class . '"'
			. ' aria-pressed="' . ($isActive ? 'true' : 'false') . '"'
			. ' data-author-id="' . $authorId . '"'
			. ' data-testid="biconnector-share-registry-author-' . $authorId . '"'
			. ' onclick="' . $onclick . '">'
			. $this->renderAvatar($authorId, 'biconnector-share-registry-author__avatar')
			. '<span class="biconnector-share-registry-author__name">' . htmlspecialcharsbx($name) . '</span>'
			. '<span class="biconnector-share-registry-author__remove" aria-hidden="true"></span>'
			. '</button>'
		;
	}

	private function renderAvatar(int $userId, string $class): string
	{
		$avatarUrl = $this->userAvatars[$userId] ?? null;

		return $avatarUrl === null
			? '<span class="' . $class . ' --empty" aria-hidden="true"></span>'
			: '<span class="' . $class . '" aria-hidden="true">'
				. '<i style="background-image: url(\'' . htmlspecialcharsbx(Uri::urnEncode($avatarUrl)) . '\')"></i>'
				. '</span>'
		;
	}

	public static function getStatusTitle(string $status): string
	{
		return Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_STATUS_' . $status) ?? $status;
	}

	private function renderStatus(string $status): string
	{
		$color = self::STATUS_LABEL_COLORS[$status] ?? 'light';
		$title = htmlspecialcharsbx(self::getStatusTitle($status));

		return '<span class="ui-label ui-label-' . $color . ' biconnector-share-registry-status"'
			. ' data-status="' . htmlspecialcharsbx($status) . '" title="' . $title . '">'
			. '<span class="ui-label-inner">' . $title . '</span>'
			. '</span>'
		;
	}

	private function renderSlice(array $data): string
	{
		$lines = [
			...$this->getFilterLines($data['EXTERNAL_FILTER_VALUES'] ?? null),
			...$this->getUrlParameterLines($data['URL_PARAMETER_VALUES'] ?? null),
		];

		if (empty($lines))
		{
			return '&mdash;';
		}

		if (count($lines) === 1)
		{
			return htmlspecialcharsbx($lines[0]);
		}

		$hint = htmlspecialcharsbx(implode('<br>', array_map('htmlspecialcharsbx', $lines)));
		$text = htmlspecialcharsbx(Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_RESTRICTIONS_MORE', [
			'#RESTRICTION#' => $lines[0],
			'#COUNT#' => count($lines) - 1,
		]) ?? $lines[0]);

		$rest = ScreenReaderText::renderRest(array_map('htmlspecialcharsbx', array_slice($lines, 1)));

		return "<span data-hint=\"{$hint}\" data-hint-html data-hint-no-icon data-hint-interactivity>{$text}</span>"
			. $rest
		;
	}

	/**
	 * @return string[]
	 */
	private function getFilterLines(?string $json): array
	{
		$lines = [];
		foreach ($this->decode($json) as $filter)
		{
			if (!is_array($filter))
			{
				continue;
			}

			$filterType = (string)($filter['filterType'] ?? '');
			$parameter = self::FILTER_TYPE_PARAMETERS[$filterType] ?? null;
			$value = $parameter
				? $this->getScopeFilterValueText($parameter, $filter)
				: $this->getStructureFilterValueText($filter)
			;
			if ($value === '')
			{
				continue;
			}

			$title = $this->getRestrictionTitle($filterType);
			$lines[] = $title === '' ? $value : "{$title}: {$value}";
		}

		return $lines;
	}

	private function getRestrictionTitle(string $filterType): string
	{
		$code = self::FILTER_TYPE_TITLE_CODES[$filterType] ?? null;

		return $code === null ? '' : (Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_RESTRICTION_' . $code) ?? '');
	}

	/**
	 * Values are shown by the names of what the restriction is built from; the labels sent by the client
	 * are only a fallback for values that are no longer found.
	 */
	private function getStructureFilterValueText(array $filter): string
	{
		$texts = [];
		$isResolved = false;
		foreach ((array)($filter['value'] ?? []) as $item)
		{
			$code = is_array($item) ? ($item['id'] ?? null) : $item;
			$name = is_string($code) ? ($this->structureNames[$code] ?? '') : '';
			if ($name === '' && is_array($item))
			{
				$name = trim((string)($item['label'] ?? $item['title'] ?? ''));
			}

			if ($name !== '')
			{
				$texts[] = $name;
				$isResolved = true;
			}
			elseif (is_scalar($code) && (string)$code !== '')
			{
				$texts[] = (string)$code;
			}
		}

		$label = trim((string)($filter['label'] ?? ''));
		if (!$isResolved && $label !== '')
		{
			return $label;
		}

		return implode(', ', $texts);
	}

	/**
	 * Flow and workflow filters keep either names or ids; ids are shown by name.
	 */
	private function getScopeFilterValueText(Parameter $parameter, array $filter): string
	{
		$values = [];
		foreach ((array)($filter['value'] ?? []) as $value)
		{
			if (is_array($value))
			{
				$value = $value['label'] ?? $value['title'] ?? $value['id'] ?? null;
			}

			$text = $this->getScopeValueText($parameter, $value);
			if ($text !== '')
			{
				$values[] = $text;
			}
		}

		if (!empty($values))
		{
			return implode(', ', $values);
		}

		return $this->stripIdPrefix(trim((string)($filter['label'] ?? '')));
	}

	private function getScopeValueText(Parameter $parameter, mixed $value): string
	{
		if (self::isId($value))
		{
			$id = (int)$value;

			return $this->scopeNames[$parameter->value][$id] ?? '#' . $id;
		}

		return is_scalar($value) ? $this->stripIdPrefix(trim((string)$value)) : '';
	}

	/**
	 * Preset labels of flows and workflows look like "[12] Name".
	 */
	private function stripIdPrefix(string $text): string
	{
		return (string)preg_replace('/^\[\d+]\s*/', '', $text);
	}

	/**
	 * @return string[]
	 */
	private function getUrlParameterLines(?string $json): array
	{
		$lines = [];
		foreach ($this->decode($json) as $code => $value)
		{
			$parameter = Parameter::tryFrom((string)$code);
			if ($parameter === Parameter::CurrentUser)
			{
				continue;
			}

			$filterType = $parameter ? array_search($parameter, self::FILTER_TYPE_PARAMETERS, true) : false;
			if ($filterType !== false)
			{
				$texts = array_filter(array_map(
					fn($item) => $this->getScopeValueText($parameter, $item),
					is_array($value) ? $value : [$value],
				));
				if (!empty($texts))
				{
					$lines[] = $this->getRestrictionTitle($filterType) . ': ' . implode(', ', $texts);
				}

				continue;
			}

			if (is_array($value))
			{
				$value = implode(', ', array_filter($value, 'is_scalar'));
			}

			if (!is_scalar($value) || (string)$value === '')
			{
				continue;
			}

			$title = $parameter?->title() ?? (string)$code;
			$lines[] = "{$title}: {$value}";
		}

		return $lines;
	}

	private function loadScopeNames(array $rowsList): void
	{
		$idsByParameter = [];
		$structureValues = [];
		foreach ($rowsList as $row)
		{
			foreach ($this->decode($row['data']['URL_PARAMETER_VALUES'] ?? null) as $code => $value)
			{
				$parameter = Parameter::tryFrom((string)$code);
				if ($parameter && in_array($parameter, self::FILTER_TYPE_PARAMETERS, true))
				{
					foreach ((array)$value as $item)
					{
						$idsByParameter[$parameter->value][] = $item;
					}
				}
			}

			foreach ($this->decode($row['data']['EXTERNAL_FILTER_VALUES'] ?? null) as $filter)
			{
				if (is_array($filter) && ($filter['filterType'] ?? null) === self::FILTER_TYPE_COMPANY_STRUCTURE)
				{
					foreach ((array)($filter['value'] ?? []) as $item)
					{
						$structureValues[] = is_array($item) ? ($item['id'] ?? null) : $item;
					}

					continue;
				}

				$parameter = is_array($filter)
					? (self::FILTER_TYPE_PARAMETERS[(string)($filter['filterType'] ?? '')] ?? null)
					: null
				;
				if ($parameter)
				{
					foreach ((array)($filter['value'] ?? []) as $item)
					{
						$idsByParameter[$parameter->value][] = is_array($item) ? ($item['id'] ?? null) : $item;
					}
				}
			}
		}

		foreach ($idsByParameter as $code => $values)
		{
			$ids = array_filter($values, static fn($value) => self::isId($value));
			$this->scopeNames[$code] = $this->scopeValueNameProvider->getNames(Parameter::from($code), $ids);
		}

		$structureValues = array_filter($structureValues, 'is_string');
		if (!empty($structureValues))
		{
			$this->structureNames = $this->structureNameProvider->getNames($structureValues);
		}
	}

	private static function isId(mixed $value): bool
	{
		return is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1);
	}

	private function decode(?string $json): array
	{
		if ($json === null || $json === '')
		{
			return [];
		}

		try
		{
			$value = Json::decode($json);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($value) ? $value : [];
	}

	private function renderLastOpened(array $data, mixed $formattedDate): string
	{
		if (($data['DATE_LAST_OPENED'] ?? null) instanceof DateTime)
		{
			return (string)$formattedDate;
		}

		return '&mdash;';
	}

	/**
	 * Who switched the link off, linked to their profile; a link switched off by the system has no one,
	 * only the date column. The provider leaves the value empty for working and expired links.
	 */
	private function renderDisabledBy(array $data): string
	{
		$disabledById = (int)($data['DISABLED_BY_ID'] ?? 0);
		$name = $this->userNames[$disabledById] ?? '';
		if ($disabledById <= 0 || $name === '')
		{
			return '';
		}

		$profileUrl = str_replace(
			'#USER_ID#',
			(string)$disabledById,
			Option::get('intranet', 'path_user', self::DEFAULT_PROFILE_PATH),
		);

		return '<a class="biconnector-share-registry-user"'
			. ' href="' . htmlspecialcharsbx($profileUrl) . '"'
			. ' data-testid="biconnector-share-registry-disabled-by-' . $disabledById . '">'
			. $this->renderAvatar($disabledById, 'biconnector-share-registry-user__avatar')
			. '<span class="biconnector-share-registry-user__name">' . htmlspecialcharsbx($name) . '</span>'
			. '</a>'
		;
	}

	private function loadUserNames(array $rowsList): void
	{
		$userIds = [];
		foreach ($rowsList as $row)
		{
			foreach (['AUTHOR_ID', 'DISABLED_BY_ID'] as $key)
			{
				$userId = (int)($row['data'][$key] ?? 0);
				if ($userId > 0 && !isset($this->userNames[$userId]))
				{
					$userIds[$userId] = $userId;
				}
			}
		}

		if (empty($userIds))
		{
			return;
		}

		$users = UserTable::getList([
			'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL', 'TITLE', 'PERSONAL_PHOTO'],
			'filter' => ['@ID' => array_values($userIds)],
		]);

		$nameFormat = \CSite::GetNameFormat();
		while ($user = $users->fetch())
		{
			$userId = (int)$user['ID'];
			$this->userNames[$userId] = \CUser::FormatName($nameFormat, $user, true, false);

			$photoId = (int)$user['PERSONAL_PHOTO'];
			if ($photoId > 0)
			{
				$photo = \CFile::ResizeImageGet(
					$photoId,
					['width' => self::AVATAR_SIZE, 'height' => self::AVATAR_SIZE],
					BX_RESIZE_IMAGE_EXACT,
				);
				if (!empty($photo['src']))
				{
					$this->userAvatars[$userId] = (string)$photo['src'];
				}
			}
		}
	}
}
