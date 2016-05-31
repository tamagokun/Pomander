<?php
namespace Pomander;

class Environment
{
    public $name, $target, $method, $adapter;
    private $config, $shell, $mysql;
    private $roles;

    public function __construct($env_name)
    {
        $this->name = $env_name;
        $this->config = $this->defaults();
        $this->roles = array("app"=>null,"db"=>null);
    }

    public function load($plugin)
    {
        if (!class_exists($plugin)) {
            $plugin = "\\Pomander\\$plugin";
            if(!class_exists($plugin)) return abort("load","Could not load plugin {$plugin}");
        }
        $plugin::load($this);
    }

    public function set($options)
    {
        foreach((array) $options as $key=>$option)
            if($option || $option === false) $this->$key = $option;
    }

    public function setup()
    {
        if($this->name == "development") $this->releases = false;
        if ($this->releases === false) {
            $this->current_dir = $this->deploy_to;
            $this->releases_dir = $this->deploy_to;
            $this->release_dir = $this->deploy_to;
            $this->shared_dir = $this->deploy_to;
            $this->cache_dir = $this->deploy_to;
        } else {
            $this->current_dir = $this->deploy_to.'/current';
            $this->releases_dir = $this->deploy_to.'/releases';
            $this->release_dir = $this->current_dir;
            $this->shared_dir = $this->deploy_to.'/shared';
            $this->cache_dir = $this->shared_dir.'/cached_copy';
        }
        $this->init_method_adapter();
    }

    public function __call($name, $arguments)
    {
        if (array_key_exists($name, get_object_vars($this))) {
            $this->config[$name] = array_shift($arguments);
        } else {
            $this->$name = array_shift($arguments);
        }

        return $this;
    }

    public function __get($prop)
    {
        if(!array_key_exists($prop, $this->config)) return null;
        $value = $this->config[$prop];
        return is_callable($value)? $value() : $value;
    }

    public function __isset($prop) { return isset($this->config[$prop]); }

    public function __set($prop,$value)
    {
        if(!isset($this->config[$prop]) || !is_array($this->config[$prop])) return $this->config[$prop] = $value;
        $this->config[$prop] = is_array($value) ? array_merge($this->config[$prop], $value) : $value;
    }

    public function new_release()
    {
        return date('Ymdhis');
    }

    public function role($key)
    {
        if(!$this->$key) return false;
        if (!$this->roles[$key]) {
            $targets = is_array($this->$key)? $this->$key : array($this->$key);
            $this->roles[$key] = new Role($targets);

            return $this->update_target($this->roles[$key]->target());
        } else {
            return $this->update_target($this->roles[$key]->next());
        }
    }

    public function next_role($key)
    {
        if( !$this->roles[$key]) return false;

        return $this->roles[$key]->has_target($this->roles[$key]->current+1);
    }

    public function multi_role_support($role,$app)
    {
        $this->role($role);
        $tasks = $app->get_tasks();
        foreach ($app->top_level_tasks as $task_name) {
            try {
                $task = $app->get_task($task_name);
                if (!$task->has_dependencies()) continue;
                $deps = $task->get_dependencies();
                if ($this->dependency_needs_multi_role($role, $deps)) {
                    return $this->inject_multi_role_after($role, $task_name);
                } else {
                    foreach ($deps as $dep_name=>$dep) {
                        if (!$dep->has_dependencies()) continue;
                        if ($this->dependency_needs_multi_role($role, $dep->get_dependencies())) {
                            return $this->inject_multi_role_after($role, $dep_name);
                        }
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    public function exec($cmd)
    {
        if (!$this->target) return run_local($cmd);
        if (!$this->shell) {
            $keypass = $this->key_password;
            $auth = is_null($this->password)? $this->key_path : $this->password;
            $user = is_null($this->user)? get_current_user : $this->user;
            $this->shell = new RemoteShell($this->target, $this->port, $user, $auth, $keypass);
        }

        return $this->shell->run($cmd);
    }

    public function put($what,$where)
    {
        if ($this->target)
            $cmd = "{$this->rsync_cmd} -e \"ssh -i {$this->key_path}\" {$this->rsync_flags} $what {$this->user}@{$this->target}:$where";
        else
            $cmd = "cp -r $what $where";

        return run_local($cmd);
    }

    public function get($what,$where)
    {
        if ($this->target)
            $cmd = "{$this->rsync_cmd} -e \"ssh -i {$this->key_path}\" {$this->rsync_flags} {$this->user}@{$this->target}:$what $where";
        else
            $cmd = "cp -r $what $where";

        return run_local($cmd);
    }

    private function defaults()
    {
        $defaults = array(
            "url"=>"",
            "user"=>"",
            "repository"=>"",
            "revision"=>"",
            "branch"=>"master",
            "remote_cache"=>true,
            "releases"=>false,
            "keep_releases"=>false,
            "deploy_to"=>getcwd(),
            "backup"=>false,
            "app"=>"",
            "db"=>"",
            "method"=>"git",
            "adapter"=>"mysql",
            "port"=>22,
            "umask"=>"002",
            "key_path"=>home()."/.ssh/id_rsa",
            "key_password"=>null,
            "password"=>null,
            "rsync_cmd"=>"rsync",
            "rsync_flags"=>"-avuzPO --quiet",
            "db_backup_flags"=>"--lock-tables=FALSE --skip-add-drop-table | sed -e 's|INSERT INTO|REPLACE INTO|' -e 's|CREATE TABLE|CREATE TABLE IF NOT EXISTS|'",
            "db_swap_url"=>true,
            "composer"=>false
        );

        return $defaults;
    }

    private function update_target($target)
    {
        if (!$target) return false;
        if ($this->target == $target) return true;
        if ($this->shell) $this->shell = null;
        $this->target = $target;
        info("target",$this->target);

        return true;
    }

    private function init_method_adapter()
    {
        $method = $this->config["method"];
        if( empty($this->config["method"]) && isset($this->config["scm"]) ) $method = $this->config["scm"];
        $method = "\\Pomander\\Method\\".ucwords(strtolower($method));
        if( !$this->method = new $method($this->repository) )
            abort("method","There is no recipe for {$this->config["method"]}, perhaps create your own?");
        $adapter = "\\Pomander\\Db\\".ucwords(strtolower($this->config["adapter"]));
        if (!$this->adapter = new $adapter($this->database))
            abort("db","There is no recipe for {$this->config["adapter"]}, perhaps create your own?");
    }

    private function dependency_needs_multi_role($role, $deps)
    {
        if (in_array($role, array_keys($deps))) {
            return true;
        }

        return false;
    }

    private function inject_multi_role_after($role,$task_name)
    {
        after($task_name, function ($app) use ($task_name,$role) {
            if ($app->env->next_role($role)) {
                $app->reset();
                $app->invoke($task_name);
            }
        });
    }
}
