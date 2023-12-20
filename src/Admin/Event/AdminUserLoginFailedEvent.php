<?php


namespace ModStart\Admin\Event;


use ModStart\Core\Util\EventUtil;

class AdminUserLoginFailedEvent
{
    public $admin_user_id;
    public $username;
    public $ip;
    public $ua;

    public static function fire($admin_user_id, $username, $ip, $ua)
    {
        $event = new AdminUserLoginFailedEvent();
        $event->admin_user_id = $admin_user_id;
        $event->username = $username;
        $event->ip = $ip;
        $event->ua = $ua;
        EventUtil::fire($event);
    }
}
