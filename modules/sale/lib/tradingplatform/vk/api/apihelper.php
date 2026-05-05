<?php

namespace Bitrix\Sale\TradingPlatform\Vk\Api;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\IO;
use Bitrix\Sale\TradingPlatform\Timer;
use Bitrix\Sale\TradingPlatform\Vk\Logger;
use Bitrix\Sale\TradingPlatform\Vk\Vk;
use Bitrix\Sale\TradingPlatform\TimeIsOverException;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class ApiHelper - formatted and run requests to VK Api. Provide utility functions for help.
 * @package Bitrix\Sale\TradingPlatform\Vk\Api
 */
class ApiHelper
{
	private $vk;
	private $api;
	private $executer;
	private $exportId;
	private $logger;
	
	/**
	 * ApiHelper constructor.
	 * @param $exportId - int, ID of export profile
	 */
	public function __construct($exportId)
	{
		if (empty($exportId))
		{
			throw new ArgumentNullException('exportId');
		}
		
		$this->exportId = $exportId;
		$this->vk = Vk::getInstance();
		$this->api = $this->vk->getApi($exportId);
		$this->executer = $this->vk->getExecuter($exportId);
		$this->logger = new Logger($this->exportId);
	}
	
	
	/**
	 * Extract specified elements from array. Need to decrease of array size to post
	 *
	 * @param array $data - source array
	 * @param array $keys - array of keys, thst needed in new array
	 * @return array - array of extracted items
	 */
	public static function extractItemsFromArray($data = array(), $keys = array())
	{
		if (!isset($keys) || empty($keys))
			return $data;
		
		$newArr = array();
		foreach ($data as $value)
		{
			if (!is_array($value))
			{
				$newArr[] = $value;
			}
			else
			{
				$currArr = array();
				foreach ($keys as $k)
				{
					$currArr[$k] = $value[$k];
				}
				$newArr[] = $currArr;
			}
		}
		
		return $newArr;
	}
	
	
	/**
	 * Merge to arrays by reference key
	 *
	 * @param array $data
	 * @param array $result
	 * @param $referenceKey - main key in both arrays
	 * @return array
	 */
	public static function addResultToData($data = array(), $result = array(), $referenceKey)
	{
		if (empty($result) || !isset($referenceKey))
		{
			return $data;
		}
		
		foreach ($result as $item)
		{
			if (isset($data[$item[$referenceKey]]))
			{
				$data[$item[$referenceKey]] += $item;
			}
		}
		
		return $data;
	}
	
	
	/**
	 * Reformat array - change main (top level) key.
	 *
	 * @param array $data
	 * @param $mainKey
	 * @param string $keyRename - if isset, new main key will be rename
	 * @return array
	 */
	public static function changeArrayMainKey($data = array(), $mainKey, $keyRename = '')
	{
		if (!isset($mainKey))
			return $data;

		$result = array();
		foreach ($data as $item)
		{
			$result[$item[$mainKey]] = $item;
			if ($keyRename)
			{
				$result[$item[$mainKey]][$keyRename] = $result[$item[$mainKey]][$mainKey];
				unset($result[$item[$mainKey]][$mainKey]);
			}
		}
		
		return $result;
	}

	public function getUserGroupsSelector($selectedValue = null, $name = null, $id = null)
	{
//		todo: maybe cached this values
		$groupsSelector = false;
		
		$gpoups = $this->getUserGroups();
		if(is_array($gpoups) && !empty($gpoups))
		{
			$groupsSelector = '<option value="-1">['.Loc::getMessage('SALE_VK_CHANGE_GROUP').']</option>';
			$selectedValue = str_replace('-', '', $selectedValue);
			$name = $name ? ' name="' . $name . '"' : '';
			$id = $id ? ' id="' . $id . '"' : '';
			
			foreach ($gpoups as $group)
			{
				$selected = $selectedValue == $group["id"] ? ' selected' : '';
				$groupsSelector .=
					'<option' . $selected . ' value="' . $group['id'] . '">' . $group['name'] . '</option>';
			}
			
			$groupsSelector =
				'<select id="vk_export_groupselector" onchange="BX.Sale.VkAdmin.changeVkGroupLink();"' . $id . $name . '>' .
				$groupsSelector .
				'</select>';
			$groupsSelector.=
				'<span style="padding-left:10px">
					<a href="https://vk.ru/club'. $selectedValue .'" id="vk_export_groupselector__link">
						<img src="/bitrix/images/sale/vk/vk_icon.png">
					</a>
				</span>';
		}
		
		return $groupsSelector;
	}
	
	
	private function getUserGroups($offset = null)
	{
		$userGroups = array();
		$stepCount = 0;
		
//		max 1000 in one step.Check this value and run api again if needed
		while(true)
		{
			$params = array(
				'extended' => 1,
				'filter' => 'editor',
				'offset' => $stepCount,
				'count' => Vk::GROUP_GET_STEP,
			);
			$apiResult = $this->api->run('groups.get', $params);
			foreach($apiResult['items'] as $group)
			{
				$userGroups[$group['id']] = array(
					'id' => $group['id'],
					'name' => $group['name']
				);
			}
			
//			increment step items counter
			if($apiResult['count'] > Vk::GROUP_GET_STEP + $stepCount)
				$stepCount += Vk::GROUP_GET_STEP;
			else
				break;
		}
		
		return $userGroups;
	}
	
	/**
	 * Get list of VK albums from VK API
	 *
	 * @param $vkGroupId
	 * @param bool $flip
	 * @return array - list of VK albums
	 */
	public function getALbumsFromVk($vkGroupId, $flip = true)
	{
//		todo: so slow api request. Try cached this data or other acceleration techniques
		$albumsFromVk = $this->executer->executeMarketAlbumsGet(array(
			"owner_id" => $vkGroupId,
			"offset" => 0,
			"count" => Vk::MAX_ALBUMS,
		));
		$albumsFromVk = $albumsFromVk["items"];        //		get only items from response
		foreach ($albumsFromVk as &$item)    //		get only IDs from response
		{
			$item = $item["id"];
		}
		if ($flip)
			$albumsFromVk = array_flip($albumsFromVk);        // we need albumID as keys
		
		return $albumsFromVk;
	}
	
	
	/**
	 * Get list of VK products from VK API
	 *
	 * @param $vkGroupId
	 * @return array -  list of VK products
	 */
	public function getProductsFromVk($vkGroupId)
	{
		$productsFromVk = array();
		$prodGetStep = 0;
		while ($prodGetStep < Vk::MAX_PRODUCTS_IN_ALBUM)
		{
			$productsFromVk += $this->executer->executeMarketProductsGet(array(
				"owner_id" => $vkGroupId,
				"offset" => $prodGetStep,
				"step" => Vk::PRODUCTS_GET_STEP)
			);
			$prodGetStep += Vk::PRODUCTS_GET_STEP;
			// exit from loop, if we reach end of VK-products
			if ($productsFromVk["end_products"])
			{
				unset($productsFromVk["end_products"]);
				break;
			}
		}
		
		$result = array();
		foreach($productsFromVk as $productFromVk)
			$result[$productFromVk] = array("VK_ID" => $productFromVk);
		
		return $result;
	}
	
	
	/**
	 * Check params for save products data.
	 * Check photos, description, vk-category
	 *
	 * @param $data
	 * @return array - prepared to save data array
	 */
	public static function prepareProductsDataToVk($data)
	{
		$result = array();
		foreach ($data as $item)
		{
//			check PHOTOS and formatted
			if (isset($item["PHOTOS"]) && is_array($item["PHOTOS"]))
			{
				$photosIds = array();
				foreach ($item["PHOTOS"] as $photo)
				{
					if (is_numeric($photo["PHOTO_VK_ID"]))
						$photosIds[] = $photo["PHOTO_VK_ID"];
				}
				
				if (!empty($photosIds))
					$item["PHOTOS"] = implode(",", $photosIds);
				else
					unset($item["PHOTOS"]);
			}
			
//			check VK_CATEGORY
			if (!(isset($item["CATEGORY_VK"]) && intval($item["CATEGORY_VK"]) > 0))
			{
				$item["CATEGORY_VK"] = Vk::VERY_DEFAULT_VK_CATEGORY;
			}    // we need some category
			
			$result[] = $item;
		}
		
		return $result;
	}
	
	

	
	
	/**
	 * Get list of VK product categories from VK API
	 *
	 * @param int $count
	 * @param int $offset
	 * @return array - Get list of VK product categories. Return false if error
	 */
	public function getVkCategories($count = Vk::MAX_VK_CATEGORIES, $offset = 0)
	{
		$vkCats = $this->api->run('market.getCategories', array("count" => $count, "offset" => $offset));
		
		if (!empty($vkCats))
		{
			return $vkCats["items"];
		}
		
		else
		{
			return false;
		}
	}
}