<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Block\BlockRepo;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Landing\Manager;

class PrepareBlocksData extends Blank
{
	private array $data;

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
