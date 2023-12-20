<?php


namespace ModStart\Admin\Event;


use ModStart\Core\Util\EventUtil;

class AdminUserLoginedEvent
{
    public $admin_user_id;
    public $ip;
    public $ua;

    public static function fire($admin_user_id, $ip, $ua)
    {
        $event = new AdminUserLoginedEvent();
        $event->admin_user_id = $admin_user_id;
        $event->ip = $ip;
        $event->ua = $ua;
        EventUtil::fire($event);
    }
}
