<?php

namespace Zifan\AddressParser\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class AddressParser
 * @method static array smart(string $address, bool $has_user_info = true)
 */
class AddressParser extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'addressparser';
    }
}
