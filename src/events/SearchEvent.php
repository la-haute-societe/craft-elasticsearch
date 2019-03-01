<?php
/**
 * @link http://www.lahautesociete.com
 * @copyright Copyright (c) 2019 La Haute Société
 */

namespace lhs\elasticsearch\events;


use yii\base\Event;

/**
 * SearchEvent class
 *
 * @author albanjubert
 **/
class SearchEvent extends Event
{
    public $query;
}
