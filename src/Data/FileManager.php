<?php


namespace ModStart\Data;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Str;
use ModStart\Core\Assets\AssetsUtil;
use ModStart\Core\Dao\ModelUtil;
use ModStart\Core\Exception\BizException;
use ModStart\Core\Input\InputPackage;
use ModStart\Core\Input\Response;
use ModStart\Core\Util\ArrayUtil;
use ModStart\Core\Util\FileUtil;
use ModStart\Core\Util\ImageUtil;
use ModStart\Core\Util\PathUtil;
use ModStart\Core\Util\TreeUtil;
use ModStart\Data\Event\DataUploadedEvent;
use ModStart\Data\Event\DataUploadingEvent;
use ModStart\Data\Support\FileManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileManager
{
    public static $slowDebug = false;

    public static function handleUpload($category, $option = null, $permitCheck = null)
    {
        if (self::$slowDebug) {
            sleep(10);
        }
        $input = InputPackage::buildFromInput();
        $action = $input->getTrimString('action', 'upload');
        if ($permitCheck) {
            call_user_func($permitCheck, $action);
        }
        switch ($action) {
            case 'init':
            case 'upload':
                $func = "${action}Execute";
                return self::$func($input, $category, null, null, 0, $option);
        }
        return Response::jsonError('Unknown action');
    }

    public static function handle($category, $uploadTable, $uploadCategoryTable, $user_id, $option = null, $permitCheck = null)
    {
        if (self::$slowDebug) {
            sleep(10);
        }
        $input = InputPackage::buildFromInput();
        $action = $input->getTrimString('action', 'upload');
        if ($permitCheck) {
            call_user_func($permitCheck, $action);
        }
        switch ($action) {
            case 'config':
                // upload and save to user space
            case 'uploadDirect':
                // update and not save to user space
            case 'uploadDirectRaw':
            case 'categoryEdit':
            case 'categoryDelete':
            case 'init':
            case 'upload':
            case 'save':
            case 'saveRaw':
            case 'fileEdit':
            case 'fileDelete':
            case 'uploadAndSaveBase64':
            case 'list':
            case 'category':
                $func = "${action}Execute";
                return self::$func($input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option);
        }
        return Response::jsonError('Unknown action');
    }

    private static function configExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        $data = DataManager::uploadConfig($category);
        return Response::generateSuccessData($data);
    }

    private static function fileDeleteExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        $ids = $input->getStringSeparatedArray('id');
        BizException::throwsIfEmpty('id empty', $ids);
        DB::transaction(function () use ($ids, $uploadTable, $uploadCategoryTable, $user_id, $option) {
            foreach ($ids as $id) {
                $upload = ModelUtil::get($uploadTable, ['id' => $id, 'user_id' => $user_id,]);
                if (empty($upload)) continue;
                DataManager::deleteById($upload['data_id'], $option);
                ModelUtil::delete($uploadTable, ['id' => $id, 'user_id' => $user_id,]);
            }
        });
        return Response::jsonSuccess();
    }

    private static function fileEditExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        $ids = $input->getStringSeparatedArray('id');
        $categoryId = $input->getInteger('categoryId');
        BizException::throwsIfEmpty('id empty', $ids);
        ModelUtil::model($uploadTable)->whereIn('id', $ids)->where(['user_id' => $user_id])->update(['upload_category_id' => $categoryId]);
        return Response::jsonSuccess();
    }

    private static function saveToUser($data, $category, $categoryId, $uploadTable, $user_id)
    {
        if ($category === 'image') {
            if (empty($data['driver'])) {
                ImageUtil::limitSizeAndDetectOrientation(
                    AbstractDataStorage::DATA . "/$category/$data[path]",
                    config('data.upload.image.maxWidth', 9999),
                    config('data.upload.image.maxHeight', 9999)
                );
            }
        }
        ModelUtil::insert($uploadTable, [
            'user_id' => $user_id,
            'category' => $data['category'],
            'data_id' => $data['id'],
            'upload_category_id' => $categoryId,
        ]);
        return Response::generateSuccessData(ArrayUtil::keepKeys($data, ['path', 'category', 'size', 'filename']));
    }

    /**
     * 直接上传模式（不分片），用户文件管理中可见
     *
     * @param InputPackage $input
     * @param $category
     * @param $uploadTable
     * @param $uploadCategoryTable
     * @param $user_id
     * @param $option
     * @return mixed
     * @throws \Exception
     */
    private static function uploadDirectExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        DataUploadingEvent::fire($uploadTable, $user_id, $category);
        /** @var UploadedFile $file */
        $file = Input::file('file');
        if (empty($file)) {
            return Response::jsonError('file empty');
        }
        $filename = $file->getClientOriginalName();
        $content = file_get_contents($file->getRealPath());
        $ret = DataManager::upload($category, $filename, $content, $option);
        if ($ret['code']) {
            return Response::jsonError($ret['msg']);
        }
        $retSaveUser = self::saveToUser($ret['data']['data'], $category, -1, $uploadTable, $user_id);
        if ($retSaveUser['code']) {
            return Response::jsonError($ret['msg']);
        }
        $data = [
            'id' => $ret['data']['data']['id'],
            'path' => $ret['data']['path'],
            'fullPath' => $ret['data']['fullPath'],
            'filename' => $retSaveUser['data']['filename'],
            'data' => $retSaveUser['data'],
        ];
        if (Input::get('fullPath', false)) {
            $data['fullPath'] = PathUtil::fixFull($data['fullPath']);
        }
        DataUploadedEvent::fire($uploadTable, $user_id, $category, $data['id']);
        return Response::jsonSuccessData($data);
    }

    /**
     * 直接上传模式（不分片），用户文件管理中不可见
     *
     * @param InputPackage $input
     * @param $category
     * @param $uploadTable
     * @param $uploadCategoryTable
     * @param $user_id
     * @param $option
     * @return mixed
     * @throws \Exception
     */
    private static function uploadDirectRawExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        DataUploadingEvent::fire($uploadTable, $user_id, $category);
        /** @var UploadedFile $file */
        $file = Input::file('file');
        if (empty($file)) {
            return Response::jsonError('file empty');
        }
        $filename = $file->getClientOriginalName();
        $content = file_get_contents($file->getRealPath());
        $ret = DataManager::upload($category, $filename, $content, $option);
        if ($ret['code']) {
            return Response::jsonError($ret['msg']);
        }
        DataUploadedEvent::fire($uploadTable, $user_id, $category, $ret['data']['data']['id']);
        return Response::jsonSuccessData([
            'path' => $ret['data']['path'],
            'fullPath' => $ret['data']['fullPath'],
            'filename' => $ret['data']['data']['filename'],
            'data' => $ret['data']['data'],
        ]);
    }

    private static function uploadAndSaveBase64Execute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        DataUploadingEvent::fire($uploadTable, $user_id, $category);
        $input = InputPackage::buildFromInput();
        $filename = $input->getTrimString('filename');
        $content = $input->getBase64Image('data');
        $ret = DataManager::upload($category, $filename, $content, $option);
        if ($ret['code']) {
            return Response::jsonError($ret['msg']);
        }
        $retSaveUser = self::saveToUser($ret['data']['data'], $category, -1, $uploadTable, $user_id);
        if ($retSaveUser['code']) {
            return Response::jsonError($ret['msg']);
        }
        DataUploadedEvent::fire($uploadTable, $user_id, $category, $ret['data']['data']['id']);
        return Response::jsonSuccessData([
            'path' => $ret['data']['path'],
            'fullPath' => $ret['data']['fullPath'],
            'filename' => $retSaveUser['data']['filename'],
            'data' => $retSaveUser['data'],
        ]);
    }

    private static function saveExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        DataUploadingEvent::fire($uploadTable, $user_id, $category);
        $path = $input->getTrimString('path');
        $categoryId = $input->getInteger('categoryId', -1);
        BizException::throwsIfEmpty('path empty', $path);
        $ret = DataManager::storeTempDataByPath($path, $option);
        if ($ret['code']) {
            return Response::jsonError($ret['msg']);
        }
        $data = $ret['data']['data'];
        $retSaveUser = self::saveToUser($data, $category, $categoryId, $uploadTable, $user_id);
        if ($retSaveUser['code']) {
            return Response::jsonError($ret['msg']);
        }
        DataUploadedEvent::fire($uploadTable, $user_id, $category, $ret['data']['data']['id']);
        return Response::jsonSuccessData([
            'data' => ArrayUtil::keepKeys($data, ['path', 'category', 'size', 'filename']),
            'fullPath' => $ret['data']['fullPath'],
        ]);
    }

    /**
     * 保存到文件库中，
     *
     * @param InputPackage $input
     * @param $category
     * @param $uploadTable
     * @param $uploadCategoryTable
     * @param $user_id
     * @param $option
     * @return mixed
     * @throws BizException
     */
    private static function saveRawExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        DataUploadingEvent::fire($uploadTable, $user_id, $category);
        $path = $input->getTrimString('path');
        $categoryId = max($input->getInteger('categoryId'), 0);
        BizException::throwsIfEmpty('path empty', $path);
        $ret = DataManager::storeTempDataByPath($path, $option);
        if ($ret['code']) {
            return Response::jsonError($ret['msg']);
        }
        $data = $ret['data']['data'];
        DataUploadedEvent::fire($uploadTable, $user_id, $category, $ret['data']['data']['id']);
        return Response::jsonSuccessData([
            'data' => ArrayUtil::keepKeys($data, ['path', 'category', 'size', 'filename']),
            'fullPath' => $ret['data']['fullPath'],
        ]);
    }

    private static function initExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        DataUploadingEvent::fire($uploadTable, $user_id, $category);
        return DataManager::uploadHandle($category, Input::all(), ['user_id' => $user_id], $option);
    }

    private static function uploadExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        DataUploadingEvent::fire($uploadTable, $user_id, $category);
        return DataManager::uploadHandle($category, Input::all(), ['user_id' => $user_id], $option);
    }

    private static function listExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        $page = $input->getPage();
        $pageSize = $input->getPageSize(null, null, null, 24);
        $categoryId = $input->getTrimString('categoryId');
        if (Str::startsWith($categoryId, ':')) {
            $pcs = explode(':', $categoryId);
            BizException::throwsIf('Unsupported category id', count($pcs) < 2);
            $provider = FileManagerProvider::getByName($pcs[1]);
            BizException::throwsIfEmpty('provider not found', $provider);
            $categoryId = (isset($pcs[2]) ? trim($pcs[2]) : null);
            return $provider->listExecute($category, $categoryId, [
                'uploadTable' => $uploadTable,
                'uploadCategoryTable' => $uploadCategoryTable,
                'user_id' => $user_id,
                'option' => $option,
            ]);
        }
        $categoryId = intval($categoryId);
        $option = [];
        $option['order'] = ['id', 'desc'];
        $option['where'] = ['user_id' => $user_id, 'category' => $category];
        if ($categoryId > 0) {
            $uploadCategories = ModelUtil::all($uploadCategoryTable, ['user_id' => $user_id,]);
            $childIds = TreeUtil::nodesChildrenIds($uploadCategories, $categoryId);
            $childIds[] = $categoryId;
            $option['whereIn'] = ['upload_category_id', $childIds];
        } else if ($categoryId == 0) {
            $option['whereOperate'] = ['upload_category_id', '>=', 0];
        } else if ($categoryId == -1) {
            $option['where']['upload_category_id'] = -1;
        }
        $paginateData = ModelUtil::paginate($uploadTable, $page, $pageSize, $option);
        ModelUtil::join($paginateData['records'], 'data_id', '_data', 'data', 'id');
        $records = [];
        foreach ($paginateData['records'] as $record) {
            $item = [];
            $item['id'] = $record['id'];
            $item['path'] = config('data.baseUrl', '/') . AbstractDataStorage::DATA . '/' . $record['_data']['category'] . '/' . $record['_data']['path'];
            if (!empty($record['_data']['domain'])) {
                $item['path'] = $record['_data']['domain'] . $item['path'];
            }
            $item['fullPath'] = AssetsUtil::fixFull($item['path'], false);
            $item['filename'] = htmlspecialchars($record['_data']['filename']);
            $item['type'] = FileUtil::extension($record['_data']['path']);
            $item['category'] = $category;
            $records[] = $item;
        }
        return Response::generateSuccessPaginateData($page, $pageSize, $records, $paginateData['total']);
    }

    private static function categoryDeleteExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        $id = $input->getInteger('id');
        $category = ModelUtil::get($uploadCategoryTable, ['id' => $id, 'user_id' => $user_id,]);
        BizException::throwsIfEmpty(L('Category not exists'), $category);
        $uploadCategories = ModelUtil::all($uploadCategoryTable, ['user_id' => $user_id,]);
        $childIds = TreeUtil::nodesChildrenIds($uploadCategories, $id);
        $childIds[] = $id;
        foreach ($childIds as $childId) {
            ModelUtil::update($uploadTable, ['user_id' => $user_id, 'upload_category_id' => $childId], ['upload_category_id' => 0]);
        }
        foreach ($childIds as $childId) {
            ModelUtil::delete($uploadCategoryTable, ['user_id' => $user_id, 'id' => $childId]);
        }
        return Response::jsonSuccess();
    }

    private static function categoryEditExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        $id = $input->getInteger('id');
        $pid = $input->getInteger('pid');
        $title = $input->getTrimString('title');
        BizException::throwsIfEmpty(L('Title required'), $title);
        if ($id) {
            $category = ModelUtil::get($uploadCategoryTable, ['id' => $id, 'user_id' => $user_id,]);
            BizException::throwsIfEmpty(L('Category not exists'), $category);
            BizException::throwsIf(L('Category cannot changed'), !TreeUtil::modelNodeChangeAble($uploadCategoryTable, $id, $category['pid'], $pid));
            ModelUtil::update($uploadCategoryTable, ['id' => $id, 'user_id' => $user_id,], [
                'pid' => $pid,
                'sort' => null,
                'title' => $title,
            ]);
        } else {
            ModelUtil::insert($uploadCategoryTable, [
                'user_id' => $user_id,
                'category' => $category,
                'pid' => $pid,
                'sort' => null,
                'title' => $title,
            ]);
        }
        return Response::jsonSuccess();
    }

    private static function categoryExecute(InputPackage $input, $category, $uploadTable, $uploadCategoryTable, $user_id, $option)
    {
        $uploadCategories = ModelUtil::all($uploadCategoryTable, ['user_id' => $user_id, 'category' => $category]);
        $categories = [];
        foreach ($uploadCategories as $uploadCategory) {
            $categories[] = [
                'name' => $uploadCategory['title'],
                'id' => $uploadCategory['id'],
                'pid' => $uploadCategory['pid'],
                'sort' => $uploadCategory['sort'],
            ];
        }
        $categoryTree = TreeUtil::nodesToTree($categories);
        $categoryTreeParent = [
            [
                'name' => L(ucfirst($category) . ' Gallery'),
                '_child' => $categoryTree,
                'id' => 0,
            ],
        ];
        $categoryTreeAll = [
            [
                'name' => L(ucfirst($category) . ' Gallery'),
                '_child' => $categoryTree,
                'id' => 0,
            ],
            [
                'name' => L('Unclassified'),
                '_child' => [],
                'id' => -1,
            ]
        ];
        foreach (FileManagerProvider::listAll() as $provider) {
            $categoryTree = $provider->getCategoryTree($category, [
                'uploadTable' => $uploadTable,
                'uploadCategoryTable' => $uploadCategoryTable,
                'user_id' => $user_id,
                'option' => $option,
            ]);
            if (!empty($categoryTree)) {
                $categoryTreeAll[] = $categoryTree;
            }
        }
        $categoryListParent = TreeUtil::treeToListWithIndent($categoryTreeParent, 'id', 'name');
        $categoryListAll = TreeUtil::treeToListWithIndent($categoryTreeAll, 'id', 'name');
        return Response::jsonSuccessData([
            'categoryTreeParent' => $categoryTreeParent,
            'categoryListParent' => $categoryListParent,
            'categoryTreeAll' => $categoryTreeAll,
            'categoryListAll' => $categoryListAll,
            'categories' => $categories,
        ]);

    }
}
