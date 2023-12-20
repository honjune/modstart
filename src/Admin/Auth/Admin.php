<?php


namespace ModStart\Admin\Auth;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use ModStart\Admin\Event\AdminUserLoginAttemptEvent;
use ModStart\Admin\Event\AdminUserLoginFailedEvent;
use ModStart\Admin\Type\AdminLogType;
use ModStart\Core\Dao\ModelUtil;
use ModStart\Core\Input\Request;
use ModStart\Core\Input\Response;
use ModStart\Core\Util\AgentUtil;
use ModStart\Core\Util\SerializeUtil;
use ModStart\Core\Util\StrUtil;

class Admin
{
    const ADMIN_USER_ID_SESSION_KEY = '_admin_user_id';

    public static function isLogin()
    {
        return self::id() > 0;
    }

    public static function isFounder()
    {
        $id = self::id();
        return $id > 0 && AdminPermission::isFounder($id);
    }

    public static function isNotLogin()
    {
        return !self::isLogin();
    }

    public static function id()
    {
        return intval(Session::get(self::ADMIN_USER_ID_SESSION_KEY, null));
    }

    public static function get($admin_user_id)
    {
        return ModelUtil::get('admin_user', ['id' => $admin_user_id]);
    }

    public static function getByUsername($username)
    {
        return ModelUtil::get('admin_user', ['username' => $username]);
    }

    public static function passwordEncrypt($password, $password_salt)
    {
        return md5(md5($password) . md5($password_salt));
    }

    public static function add($username, $password, $ignorePassword = false)
    {
        $password_salt = Str::random(16);
        $data = [];
        $data['username'] = $username;
        if (!$ignorePassword) {
            $data['password_salt'] = $password_salt;
            $data['password'] = self::passwordEncrypt($password, $password_salt);
        }
        return ModelUtil::insert('admin_user', $data);
    }

    public static function loginByPhone($phone)
    {
        $adminUser = ModelUtil::get('admin_user', ['phone' => $phone]);
        if (empty($adminUser)) {
            AdminUserLoginFailedEvent::fire(0, null, Request::ip(), AgentUtil::getUserAgent());
            return Response::generate(-1, L('User Not Exists'));
        }
        AdminUserLoginAttemptEvent::fire($adminUser['id'], Request::ip(), AgentUtil::getUserAgent());
        ModelUtil::update('admin_user', $adminUser['id'], [
            'last_login_ip' => StrUtil::mbLimit(Request::ip(), 20),
            'last_login_time' => Carbon::now(),
        ]);
        return Response::generateSuccessData($adminUser);
    }

    public static function login($username, $password)
    {
        $adminUser = ModelUtil::get('admin_user', ['username' => $username]);
        if (empty($adminUser)) {
            AdminUserLoginFailedEvent::fire(0, $username, Request::ip(), AgentUtil::getUserAgent());
            return Response::generate(-1, L('User Not Exists'));
        }
        AdminUserLoginAttemptEvent::fire($adminUser['id'], Request::ip(), AgentUtil::getUserAgent());
        if ($adminUser['password'] != self::passwordEncrypt($password, $adminUser['password_salt'])) {
            AdminUserLoginFailedEvent::fire($adminUser['id'], $username, Request::ip(), AgentUtil::getUserAgent());
            return Response::generate(-2, L('Password Incorrect'));
        }
        ModelUtil::update('admin_user', $adminUser['id'], [
            'last_login_ip' => StrUtil::mbLimit(Request::ip(), 20),
            'last_login_time' => Carbon::now(),
        ]);
        return Response::generateSuccessData($adminUser);
    }

    public static function rule_changed($admin_user_id, $rule_changed)
    {
        ModelUtil::update('admin_user', ['id' => $admin_user_id], ['rule_changed' => boolval($rule_changed)]);
    }

    public static function listRolesByUserId($admin_user_id)
    {
        $adminUser = ModelUtil::get('admin_user', $admin_user_id);
        if (empty($adminUser)) {
            return Response::generate(-1, L('User Not Exists'));
        }
        $roles = ModelUtil::all('admin_user_role', ['user_id' => $admin_user_id], ['role_id']);
        ModelUtil::join($roles, 'role_id', 'role', 'admin_role', 'id');
        foreach ($roles as $k => $role) {
            $roles[$k]['name'] = $role['role']['name'];
        }
        ModelUtil::joinAll($roles, 'role_id', 'rules', 'admin_role_rule', 'role_id');
        return Response::generate(0, null, $roles);
    }

    public static function changePassword($id, $old, $new, $ignoreOld = false)
    {
        $adminUser = ModelUtil::get('admin_user', ['id' => $id]);
        if (empty($adminUser)) {
            return Response::generate(-1, L('Admin user not exists'));
        }
        if ($adminUser['password'] != self::passwordEncrypt($old, $adminUser['password_salt'])) {
            if (!$ignoreOld) {
                return Response::generate(-1, L('Old Password Incorrect'));
            }
        }
        $password_salt = Str::random(16);
        $data = [];
        $data['password'] = self::passwordEncrypt($new, $password_salt);
        $data['password_salt'] = $password_salt;
        $data['last_change_pwd_time'] = Carbon::now();
        ModelUtil::update('admin_user', ['id' => $adminUser['id']], $data);
        return Response::generate(0, 'ok');
    }

    public static function addInfoLog($admin_user_id, $summary, $content = [])
    {
        static $exists = null;
        if (null === $exists) {
            $exists = Schema::hasTable('admin_log');
        }
        if (!$exists) {
            return;
        }
        $adminLog = ModelUtil::insert('admin_log', ['admin_user_id' => $admin_user_id, 'type' => AdminLogType::INFO, 'summary' => $summary]);
        if (!empty($content)) {
            ModelUtil::insert('admin_log_data', ['id' => $adminLog['id'], 'content' => SerializeUtil::jsonEncode($content)]);
        }
    }

    public static function addErrorLog($admin_user_id, $summary, $content = [])
    {
        static $exists = null;
        if (null === $exists) {
            $exists = Schema::hasTable('admin_log');
        }
        if (!$exists) {
            return;
        }
        $adminLog = ModelUtil::insert('admin_log', ['admin_user_id' => $admin_user_id, 'type' => AdminLogType::ERROR, 'summary' => $summary]);
        if (!empty($content)) {
            ModelUtil::insert('admin_log_data', ['id' => $adminLog['id'], 'content' => SerializeUtil::jsonEncode($content)]);
        }
    }

    public static function addInfoLogIfChanged($admin_user_id, $summary, $old, $new)
    {
        $changed = [];
        if (empty($old) && empty($new)) {
            return;
        }
        foreach ($old as $k => $oldValue) {
            if (!array_key_exists($k, $new)) {
                $changed['Delete:' . $k . ':Old'] = $oldValue;
                continue;
            }
            if ($new[$k] != $oldValue) {
                $changed['Change:' . $k . ':Old'] = $oldValue;
                continue;
            }
        }
        foreach ($new as $k => $newValue) {
            if (!array_key_exists($k, $old)) {
                $changed['Add:' . $k . ':New'] = $newValue;
                continue;
            }
        }
        if (empty($changed)) {
            return;
        }
        self::addInfoLog($admin_user_id, $summary, $changed);
    }


}
