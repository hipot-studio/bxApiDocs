<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Block\BlockRepo;
use Bitrix\Landing\Subtype\Form;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Landing\Manager;

class PrepareBlocksData extends Blank
{
	/**
	 * Node and menu keys holding a link: only a value that is entirely a marker is a binding, the
	 * same boundary the public render draws (Subtype\Form::replaceFormMarkers), so an external url
	 * merely ending with such an anchor stays as it was exported.
	 */
	private const WHOLE_MARKER_KEYS = ['href'];

	/**
	 * Node and menu keys holding a pseudo-url json: there the marker sits inside the value.
	 */
	private const EMBEDDED_MARKER_KEYS = ['url', 'data-pseudo-url'];

	private array $data;
	private array $activeFormIdCache = [];

	public function action(): void
	{
		$this->data = $this->context->getData();
		if (empty($this->data) || empty($this->data['BLOCKS']))
		{
			return;
		}

		$this->fixWrapperClasses();
		$this->deleteCopyrightBlock();
		$this->fixContactDataAndCountdown();
		$this->normalizeCrmFormBinding();
		$this->enableHiddenBlocksForCreatingPage();

		$this->context->setData($this->data);
	}

	private function fixWrapperClasses(): void
	{
		// @fix wrapper classes from original
		$appCode = $this->data['INITIATOR_APP_CODE'] ?? '';
		$newTplCode =
			(string)(
				$this->context->getRunData()->get(RunDataPart::PreviousTplCode)
				?? $this->data['TPL_CODE']
			);
		$delobotAppCode = 'local.5eea949386cd05.00160385';
		$kraytAppCode = 'local.5f11a19f813b13.97126836';
		$bitrixAppCode = 'bitrix.';
		if (
			str_contains($newTplCode, $delobotAppCode)
			|| str_contains($newTplCode, $kraytAppCode)
			|| str_starts_with($appCode, $bitrixAppCode)
		)
		{
			$wrapperClasses = [];
			$http = new HttpClient;
			$resPreview = $http->get(Manager::getPreviewHost() . '/tools/blocks.php?tplCode=' . $newTplCode);
			if ($resPreview)
			{
				try
				{
					$wrapperClasses = Json::decode($resPreview);
				}
				catch (\Exception $e)
				{
				}
			}

			if ($wrapperClasses)
			{
				$i = 0;
				foreach ($this->data['BLOCKS'] as &$blockData)
				{
					if (isset($wrapperClasses[$i]) && $wrapperClasses[$i]['code'] === $blockData['code'])
					{
						$blockData['style']['#wrapper'] = ['classList' => [$wrapperClasses[$i]['classList']]];
					}
					$i++;
				}
				unset($blockData);
			}
		}
	}

	private function deleteCopyrightBlock(): void
	{
		$appCode = $this->data['INITIATOR_APP_CODE'] ?? '';
		$templateDateCreate = strtotime($this->data['DATE_CREATE'] ?? '');
		$lastDate = strtotime('17.02.2022 00:00:00');
		if (
			$appCode
			&& $templateDateCreate
			&& $templateDateCreate < $lastDate
		)
		{
			$kraytCode = 'bitrix.krayt';
			$delobotCode = 'bitrix.delobot';
			if (
				str_contains($appCode, $kraytCode)
				|| str_contains($appCode, $delobotCode)
			)
			{
				if (array_slice($this->data['BLOCKS'], -1)[0]['code'] === '17.copyright')
				{
					array_pop($this->data['BLOCKS']);
				}
			}
			unset($kraytCode, $delobotCode);
		}
	}

	private function fixContactDataAndCountdown(): void
	{
		$appCode = $this->data['INITIATOR_APP_CODE'] ?? '';
		$bitrixAppCode = 'bitrix.';

		foreach ($this->data['BLOCKS'] as &$block)
		{
			// fix contact data
			if (
				isset($block['nodes'])
				&& str_starts_with($appCode, $bitrixAppCode)
			)
			{
				foreach ($block['nodes'] as &$node)
				{
					$countNodeItem = 0;
					foreach ($node as &$nodeItem)
					{
						if (isset($nodeItem['href']))
						{
							$setContactsBlockCode = [
								'14.1.contacts_4_cols',
								'14.2contacts_3_cols',
								'14.3contacts_2_cols',
							];
							if (preg_match('/^tel:.*$/i', $nodeItem['href']))
							{
								$nodeItem['href'] = 'tel:#crmPhone1';
								if (isset($nodeItem['text']))
								{
									$nodeItem['text'] = '#crmPhoneTitle1';
								}
								if (
									(isset($block['nodes']['.landing-block-node-linkcontact-text'])
										&& in_array($block['code'], $setContactsBlockCode, true))
								)
								{
									$block['nodes']['.landing-block-node-linkcontact-text'][$countNodeItem] =
										'#crmPhoneTitle1';
								}
							}
							if (preg_match('/^mailto:.*$/i', $nodeItem['href']))
							{
								$nodeItem['href'] = 'mailto:#crmEmail1';
								if (isset($nodeItem['text']))
								{
									$nodeItem['text'] = '#crmEmailTitle1';
								}
								if (
									isset($block['nodes']['.landing-block-node-linkcontact-text'])
									&& (in_array($block['code'], $setContactsBlockCode, true))
								)
								{
									$block['nodes']['.landing-block-node-linkcontact-text'][$countNodeItem] =
										'#crmEmailTitle1';
								}
							}
						}
						$countNodeItem++;
					}
					unset($nodeItem);
				}
				unset($node);
			}

			//fix countdown until the next unexpired date
			if (isset($block['attrs']))
			{
				foreach ($block['attrs'] as &$attr)
				{
					foreach ($attr as &$attrItem)
					{
						if (array_key_exists('data-end-date', $attrItem))
						{
							$neededAttr = is_numeric($attrItem['data-end-date'])
								? (int)$attrItem['data-end-date'] / 1000
								: 0;
							$currenDate = time();
							if ($neededAttr < $currenDate)
							{
								$m = date('m', $neededAttr);
								$d = date('d', $neededAttr);
								$currenDateY = (int)date('Y', $currenDate);
								$currenDateM = date('m', $currenDate);
								$currenDateD = date('d', $currenDate);
								if ($currenDateM > $m)
								{
									$y = $currenDateY + 1;
								}
								elseif (($currenDateM === $m) && $currenDateD >= $d)
								{
									$y = $currenDateY + 1;
								}
								else
								{
									$y = $currenDateY;
								}
								$time = '10:00:00';
								$timestamp = strtotime($y . '-' . $m . '-' . $d . ' ' . $time) * 1000;
								$attrItem['data-end-date'] = (string)$timestamp;

								$block['full_content'] = $block['full_content'] ?? '';
								if (preg_match_all(
									'/data-end-date="\d+"/',
									$block['full_content'],
									$matches)
								)
								{
									$block['full_content'] = str_replace(
										$matches[0],
										'data-end-date="' . $attrItem['data-end-date'] . '"',
										$block['full_content']
									);
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Rebind imported CRM form markers to the portal target form when the source form is not
	 * active here (archive brought from another portal). Same-portal transfers keep their binding.
	 */
	private function normalizeCrmFormBinding(): void
	{
		if (!$this->hasCrmFormMarker())
		{
			return;
		}

		$targetId = $this->getTargetFormId();
		if ($targetId === null)
		{
			return;
		}
		$this->loadFormActivityCache();

		foreach ($this->data['BLOCKS'] as &$block)
		{
			if (isset($block['full_content']))
			{
				$block['full_content'] = $this->normalizeFormContent((string)$block['full_content'], $targetId);
			}

			foreach (['nodes', 'menu'] as $part)
			{
				if (isset($block[$part]) && is_array($block[$part]))
				{
					$block[$part] = $this->normalizeFormMarkersDeep($block[$part], $targetId);
				}
			}

			if (isset($block['attrs']) && is_array($block['attrs']))
			{
				$block['attrs'] = $this->normalizeAttrsBinding($block['attrs'], $targetId);
			}
		}
		unset($block);

		$this->saveFormActivityCache();
	}

	/**
	 * Attrs are applied last on import (Block::saveDataToBlock), so a marker left here overwrites
	 * the already normalized content. They arrive in two shapes the import accepts:
	 * attrs[selector][position][attribute] and the compatibility one without a position,
	 * attrs[selector][attribute] (Block::setAttributes), so the attribute is looked up at any depth.
	 */
	private function normalizeAttrsBinding(array $attrs, int $targetId): array
	{
		foreach ($attrs as $key => $value)
		{
			if (is_array($value))
			{
				$attrs[$key] = $this->normalizeAttrsBinding($value, $targetId);
			}
			elseif ($key === 'data-b24form' && is_string($value))
			{
				$attrs[$key] = $this->normalizeFormMarker($value, $targetId);
			}
		}

		return $attrs;
	}

	/**
	 * Early exit for archives without any form binding: it must cover every place the pass
	 * normalizes, otherwise the target form is never resolved and bindings stay broken.
	 */
	private function hasCrmFormMarker(): bool
	{
		foreach ($this->data['BLOCKS'] as $block)
		{
			if (
				isset($block['full_content'])
				&& $this->containsFormBindingInContent((string)$block['full_content'])
			)
			{
				return true;
			}

			foreach (['nodes', 'menu'] as $part)
			{
				if (
					isset($block[$part])
					&& is_array($block[$part])
					&& $this->hasStructuredFormMarker($block[$part])
				)
				{
					return true;
				}
			}

			if (
				isset($block['attrs'])
				&& is_array($block['attrs'])
				&& $this->hasAttrFormMarker($block['attrs'])
			)
			{
				return true;
			}
		}

		return false;
	}

	private function hasStructuredFormMarker(array $values): bool
	{
		foreach ($values as $key => $value)
		{
			if (is_array($value))
			{
				if ($this->hasStructuredFormMarker($value))
				{
					return true;
				}

				continue;
			}

			if (!is_string($value))
			{
				continue;
			}

			if (
				in_array($key, self::WHOLE_MARKER_KEYS, true)
				&& $this->isWholeFormMarker($value)
			)
			{
				return true;
			}

			if (
				in_array($key, self::EMBEDDED_MARKER_KEYS, true)
				&& $this->containsFormMarker($value)
			)
			{
				return true;
			}

			if (
				// ctype extension is unavailable on the cloud, preg is the ctype_digit equivalent
				(is_int($key) || preg_match('/^[0-9]+$/D', (string)$key))
				&& $this->containsFormBindingInContent($value)
			)
			{
				return true;
			}
		}

		return false;
	}

	private function hasAttrFormMarker(array $values): bool
	{
		foreach ($values as $key => $value)
		{
			if (is_array($value))
			{
				if ($this->hasAttrFormMarker($value))
				{
					return true;
				}

				continue;
			}

			if (
				$key === 'data-b24form'
				&& is_string($value)
				&& $this->isWholeFormMarker($value)
			)
			{
				return true;
			}
		}

		return false;
	}

	private function containsFormMarker(string $value): bool
	{
		return (bool)preg_match('/' . self::getMarkerPattern() . '/i', $value);
	}

	private function isWholeFormMarker(string $value): bool
	{
		return (bool)preg_match('/^' . self::getMarkerPattern() . '$/i', $value);
	}

	private function containsFormBindingInContent(string $content): bool
	{
		if ((bool)preg_match(
			'/(?<attr>(?<![-\w])(?:data-b24form|href)\s*=\s*)(?<quote>["\'])'
			. self::getMarkerPattern()
			. '(?P=quote)/i',
			$content
		))
		{
			return true;
		}

		return (bool)preg_match(
			'/(?<attr>(?<![-\w])data-pseudo-url\s*=\s*)(?<quote>["\'])(?<value>(?:(?!(?P=quote)).)*)'
			. self::getMarkerPattern()
			. '(?:(?!(?P=quote)).)*(?P=quote)/i',
			$content
		);
	}

	/**
	 * Markers reach the content in the three shapes the public render understands
	 * (Subtype\Form::replaceFormMarkers): the binding attribute, a link href and a pseudo-url json.
	 * The lookbehind keeps data-orig-href and the like out of the match: \b does not stop at a dash.
	 */
	private function normalizeFormContent(string $content, int $targetId): string
	{
		$replaced = preg_replace_callback(
			'/(?<attr>(?<![-\w])(?:data-b24form|href)\s*=\s*)(?<quote>["\'])' . self::getMarkerPattern() . '(?P=quote)/i',
			fn(array $matches): string => $matches['attr'] . $matches['quote']
				. $this->rebindMarker($matches, $targetId)
				. $matches['quote'],
			$content
		);
		$content = $replaced ?? $content;

		$replaced = preg_replace_callback(
			'/(?<attr>(?<![-\w])data-pseudo-url\s*=\s*)(?<quote>["\'])(?<value>(?:(?!(?P=quote)).)*)(?P=quote)/i',
			fn(array $matches): string => $matches['attr'] . $matches['quote']
				. $this->normalizeFormMarkersInText($matches['value'], $targetId)
				. $matches['quote'],
			$content
		);

		return $replaced ?? $content;
	}

	/**
	 * Node values carry the binding in several shapes depending on the node type: link href,
	 * icon/img pseudo-url json, plain text html. A node item is an array or a scalar string.
	 * Menu items are the same shape one level deeper: href plus nested children.
	 *
	 * An href is a binding only when the whole value is the marker, a pseudo-url json carries it
	 * inside. Everything else is content the user typed (menu text is the visible text of the item,
	 * a text node is its html), so there a marker is rebound inside a known attribute only and a
	 * marker-looking word of the text stays as it was written.
	 */
	private function normalizeFormMarkersDeep(array $values, int $targetId): array
	{
		foreach ($values as $key => $value)
		{
			if (is_array($value))
			{
				$values[$key] = $this->normalizeFormMarkersDeep($value, $targetId);
			}
			elseif (is_string($value))
			{
				if (in_array($key, self::WHOLE_MARKER_KEYS, true))
				{
					$values[$key] = $this->normalizeFormMarker($value, $targetId);
				}
				elseif (in_array($key, self::EMBEDDED_MARKER_KEYS, true))
				{
					$values[$key] = $this->normalizeFormMarkersInText($value, $targetId);
				}
				else
				{
					$values[$key] = $this->normalizeFormContent($value, $targetId);
				}
			}
		}

		return $values;
	}

	private function normalizeFormMarkersInText(string $value, int $targetId): string
	{
		$replaced = preg_replace_callback(
			'/' . self::getMarkerPattern() . '/i',
			fn(array $matches): string => $this->rebindMarker($matches, $targetId),
			$value
		);

		return $replaced ?? $value;
	}

	/**
	 * A binding stored as the whole value: anything the marker syntax does not describe completely
	 * is not a binding and is left alone.
	 */
	private function normalizeFormMarker(string $value, int $targetId): string
	{
		if (!preg_match('/^' . self::getMarkerPattern() . '$/i', $value, $matches))
		{
			return $value;
		}

		return $this->rebindMarker($matches, $targetId);
	}

	/**
	 * Keeps the marker type and the optional link scheme, replaces the id only.
	 */
	private function rebindMarker(array $matches, int $targetId): string
	{
		$sourceId = (int)$matches['id'];
		$sourceActivity = $this->getSourceFormActivity($sourceId);
		$id = $sourceActivity === null
			? $sourceId
			: ($sourceActivity ? $sourceId : $targetId);

		return ($matches['scheme'] ?? '') . $matches['prefix'] . $id;
	}

	private static function getMarkerPattern(): string
	{
		return '(?<scheme>form:)?(?<prefix>'
			. preg_quote(Form::INLINE_MARKER_PREFIX, '/')
			. '|' . preg_quote(Form::POPUP_MARKER_PREFIX, '/')
			. ')(?<id>\d+)';
	}

	private function getSourceFormActivity(int $formId): ?bool
	{
		if (!array_key_exists($formId, $this->activeFormIdCache))
		{
			$activity = $this->getFormActivityState($formId);
			if ($activity === null)
			{
				return null;
			}

			$this->activeFormIdCache[$formId] = $activity;
		}

		return $this->activeFormIdCache[$formId];
	}

	/**
	 * The action runs once per imported page and each page closes its own Core episode, so the ratio
	 * is the only state left to reuse: on a connector portal the target form costs a REST call and
	 * must be asked for once per import, not once per page. Id 0 means "asked, portal has no active
	 * form", which is remembered too, otherwise every next page would ask again.
	 */
	private function getTargetFormId(): ?int
	{
		$ratio = $this->context->getRatio();
		$stored = $ratio->get(RatioPart::CrmFormTargetId);
		if ($stored !== null)
		{
			$storedId = (int)$stored;

			return $storedId > 0 ? $storedId : null;
		}

		$targetId = $this->resolveTargetFormId();
		if ($targetId === null && !$this->isTargetFormSnapshotAvailable())
		{
			return null;
		}

		$ratio->set(RatioPart::CrmFormTargetId, $targetId ?? 0);

		return $targetId;
	}

	private function loadFormActivityCache(): void
	{
		$stored = $this->context->getRatio()->get(RatioPart::CrmFormActivity);
		$this->activeFormIdCache = is_array($stored) ? array_map('boolval', $stored) : [];
	}

	private function saveFormActivityCache(): void
	{
		$this->context->getRatio()->set(RatioPart::CrmFormActivity, $this->activeFormIdCache);
	}

	protected function resolveTargetFormId(): ?int
	{
		return Form::resolveImportFormId();
	}

	protected function isTargetFormSnapshotAvailable(): bool
	{
		return Form::isFormsSnapshotAvailable();
	}

	protected function getFormActivityState(int $formId): ?bool
	{
		return Form::getFormActivityState($formId);
	}

	/**
	 * Pass filters to block repository for enable add blocks with type 'null' (hidden from list)
	 * @return void
	 */
	private function enableHiddenBlocksForCreatingPage(): void
	{
		$eventManager = EventManager::getInstance();
		$eventManager->addEventHandler(
			'landing',
			'onBlockRepoSetFilters',
			function (Event $event)
			{
				$result = new EventResult();
				$result->modifyFields([
					'DISABLE' => BlockRepo::FILTER_SKIP_HIDDEN_BLOCKS,
				]);

				return $result;
			}
		);
	}
}
