<?php


namespace ModStart\Data\Event;


use Illuminate\Support\Facades\Event;
use ModStart\Core\Util\EventUtil;

/**
 * 用户上传文件完成事件
 * 和 DataFileUploadedEvent 的区别为一个纯文件内容，一个是用户请求行为
 * Class DataUploadedEvent
 * @package ModStart\Data\Event
 */
class DataUploadedEvent
{
    public $uploadTable;
    public $user_id;
    public $category;
    public $data_id;

    public static function fire($uploadTable, $user_id, $category, $data_id)
    {
        $event = new static();
        $event->uploadTable = $uploadTable;
        $event->user_id = $user_id;
        $event->category = $category;
        $event->data_id = $data_id;
        EventUtil::fire($event);
    }

    public static function listen($uploadTable, $callback)
    {
        Event::listen(DataUploadedEvent::class, function (DataUploadedEvent $event) use ($uploadTable, $callback) {
            if ($event->uploadTable == $uploadTable) {
                call_user_func($callback, $event);
            }
        });
    }
}
