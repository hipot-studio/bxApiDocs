<?php
namespace Bitrix\Landing;

class LandingBlock
{
	/**
	 * Some params array.
	 * @var array
	 */
	protected $params = [];

	/**
	 * LandingBlock constructor.
	 */
	public function __construct()
	{
	}

	/**
	 * Get state of edit mode.
	 * @return boolean
	 */
	public function getEditMode()
	{
		return Landing::getEditMode();
	}

	/**
	 * Get mixed stored data of block.
	 * @param string $code Data code.
	 * @return mixed|null
	 */
	public function get($code)
	{
		if (array_key_exists($code, $this->params))
		{
			return $this->params[$code];
		}
		else
		{
			return null;
		}
	}

	/**
	 * Returns system page type required for manual add-to-basket action.
	 * @param Block $block Block instance.
	 * @return string|null
	 */
	public function getAddToBasketActionSyspageType(Block $block): ?string
	{
		return null;
	}

	/**
	 * Returns true when add-to-basket system page must be active.
	 * @param Block $block Block instance.
	 * @return bool
	 */
	public function isAddToBasketActionSyspageActiveOnly(Block $block): bool
	{
		return false;
	}

	/**
	 * Method, which will be called once time.
	 * @param array Params array.
	 * @return void
	 */
	public function init(array $params = [])
	{
	}

	/**
	 *  Method, which executes just before creating the block (first add on page).
	 * @param Block $block Block instance.
	 * @return void
	 */
	public function beforeAdd(Block $block)
	{

	}

	/**
	 *  Method, which executes just before block.
	 * @param Block $block Block instance.
	 * @return void
	 */
	public function beforeView(Block $block)
	{
	}
}
