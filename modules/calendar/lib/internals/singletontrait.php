<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Calendar\Core\Base\BaseException;

trait singletontrait
{
    /**
     * @var null|static
     */
    protected static $instance;

    protected function __construct() {}

    /**
     * @throws BaseException
     */
    public function __wakeup()
    {
        throw new BaseException('Trying to wake singleton up');
    }

    protected function __clone() {}

    /**
     * @return static
     */
    public static function getInstance()
    {
        if (null === static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }
}
