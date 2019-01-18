<?php
require APP_PATH.'Common/xunsearch/lib/XS.php';

/**
 * Class XunSearch 迅搜 扩展类
 *
 * 说明:
 * 需要根据项目目录引用XS.php
 * 项目名称为配置文件里的project.name
 * 所有方法静态调用并且能独立使用,不需要初始化
 *
 * @author Turnover <hehan123456@qq.com>
 */

class XunSearch extends XS
{
    /**
     * @var array 实例列表
     */
    public static $xs = array();

    /**
     * @var array 对象列表
     */
    public static $ojb = array();

    /**
     * @var string 项目名称
     */
    private static $conf = '';

    /**
     * @var bool 开启缓冲区
     */
    private static $buffer = false;

    /**
     * @var int 缓冲区大小
     */
    private static $bufferSize = 4;

    /**
     * 初始化
     *
     * XunSearch constructor.
     * @param string $app 项目名称
     */
    public function __construct($app = '')
    {
        parent::__construct($app);
    }

    /**
     * 初始化(单例)
     *
     * @param string $app 项目名称
     * @return mixed
     * @throws XSException
     */
    public static function instance($app = '')
    {
        try {
            if (!isset(self::$xs[$app])) {
                self::$xs[$app] = new self($app);
            }
            self::$conf = $app;
            return self::$xs[$app];
        } catch (XSException $e) {
            self::errorMsg(1);
        }
    }

    public static function getApp($app = '')
    {
        return !empty($app) ?  $app : (!empty(self::$conf) ? self::$conf : '');
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     * @throws XSException
     */
    public static function __callStatic($name, $arguments)
    {
        $appParam = !empty($arguments[0]) ? $arguments[0] : '';
        $app = self::getApp($appParam);
        $xun = self::instance($app);
        if (in_array($name,array('search','index'))) {
            if (!isset(self::$ojb[$app][$name])) {
                self::$ojb[$app][$name] = $xun->$name;
            }
            return self::$ojb[$app][$name];
        } else if (in_array($name,array('hot','suggest','corrected'))) {
            switch ($name) {
                case 'hot':    //no break
                case 'corrected':
                    $func = 'get'.ucfirst($name).'Query';
                    break;
                case 'suggest':
                    $func = 'getExpandedQuery';
                    break;
                default:
                    $func = 'getCorrectedQuery';
                    break;
            }
            $keyWords = $arguments[1];
            $list = self::search()->$func($keyWords);
            return $list;
        } else {
            self::errorMsg(2);
        }
    }

    /**
     * 获取搜索结果
     *
     * @param  string $app 项目名称
     * @param  string $keyWord 搜索关键字
     * @param  array  $filter 搜索过滤规则
     * @return array  返回数据结果和总数
     * @throws XSException
     */
    public static function listing ($app = '', $keyWord = '',$filter = array())
    {
        $search = self::search($app);
        $search->setQuery($keyWord);
        $fields = self::getFields();
        $result = array();
        if (is_array($fields) && !empty($fields)) {
            self::parseFilter($filter);
            $searchList = $search->search(null, false);
            foreach ($searchList as $k => $v) {
                foreach ($fields as $field) {
                    $result['list'][$k][$field] = $v->$field;
                    $result['list'][$k]['percent'] = $v->percent().'%';
                }
            }
        }
        $result['count'] = $search->getLastCount();
        if ($result['count'] == 0) {
            $result['corrected'] = self::corrected($app,$keyWord);
            $result['suggest']   = self::suggest($app,$keyWord);

            /**
             * 如果没搜到结果,按照建议词的第一个
             */
            $result['list'] = self::listing($app,$result['suggest'][0],$filter);
        }
        return $result;
    }

    /**
     * 过滤规则
     *
     * @param array $filter 过滤列表
     *   num 取出条数
     *   offset 偏移量
     *   fuzzy 模糊查询
     *   charset 字符集
     *   cutOf 过滤(以下的)值]
     */
    public static function parseFilter($filter = array())
    {
        $filter = array_merge(array(
            'num' => 100,
            'offset' => 0,
            'charset' => 'utf-8',
        ),$filter);
        $search = self::search();
        foreach ($filter as $k => $v) {
            switch ($k) {
                case "sort" :
                    list($column, $asc) = explode(' ', $v);
                    $search->setSort($column, $asc);
                    break;
                case "num" :
                    $search->setLimit($v, $filter['offset']);
                    break;
                case "fuzzy" :
                    $search->setFuzzy(true);
                    break;
                case "charset" :
                    $search->setCharset($v);
                    break;
                case "cutOf" :
                    $search->setCutOff($v);
            }
        }
    }

    /**
     * 异步删除
     *
     * @param string $app       项目名称
     * @param array|string $key 删除的键值 array('123',456)|$str = '123,456';
     * @param string $field     指定字段(按照配置文件字段指定)
     * @return bool             正确返回true
     * @throws XSException
     */
    public static function del($app = '',$key,$field = '')
    {
        $index  = self::index($app);
        $keyArr = self::setArray($key);
        if (empty($field)) {
            $index->del($keyArr);
        } else {
            $fields = self::getFields();
            if (in_array($field,$fields)) {
                $index->del($keyArr,$field);
            } else {
                self::errorMsg(3);
            }
        }
        return true;
    }

    /**
     * 立即删除
     *
     * @param string       $app   项目名称
     * @param array|string $key   删除的键值 array('123',456)|$str = '123,456';
     * @param string       $field 指定字段(按照配置文件字段指定)
     * @throws XSException
     */
    public static function flushDel ($app = '',$key,$field = '')
    {
        self::del($app,$key,$field);
        self::flush();
    }

    /**
     * 清空索引
     *
     * @param string $app 项目名称
     */
    public static function clean($app = '')
    {
        $index = self::index($app);
        $index->clean();
    }

    /**
     * 新增/更新索引(自动判断)
     *
     * @param string $app    项目名称
     * @param array  $data   添加数据
     * @param array  $option 设置选项
     *   flush 是否立即刷新
     *   method:数据存储方法
     *     update 强制使用更新方法
     *     add 强制使用增加方法
     *     default 自动判断
     * @return bool
     * @throws XSException
     */
    public static function store($app = '', $data = array(), $option = array())
    {
        if (is_array($data) && !empty($data)) {
            $fields = self::getFields($app);
            $diff = array_diff_key($fields,$data);
            if (empty($diff)) {
                $doc = new XSDocument();
                $index  = self::index();
                $doc->setFields($data);

                /**
                 * 开启缓冲区
                 */
                if (self::$buffer) {
                    $index->openBuffer(self::$bufferSize);
                }

                /**
                 * 选项操作
                 */
                $option = array_merge(array(
                    'flush' => true,
                    'method' => 'default',
                ),$option);
                extract($option);
                switch ($method) {
                    case 'update': //no break
                    case 'add':
                        $index->$method($doc);
                        break;
                    case 'default':  //no break
                    default:
                        $priKey = self::getFields($app,'id');
                        $search = self::search();
                        self::flush();
                        $count = $search->count($priKey.':'.$data[$priKey]);
                        if ($count > 0) {
                            $index->update($doc);
                        } else {
                            $index->add($doc);
                        }
                        break;
                }
                if ($flush) {
                    self::flush();
                }

                /**
                 * 关闭缓冲区
                 */
                self::closeBuffer();
                return true;
            } else {
                self::errorMsg(4);
            }
        } else {
            self::errorMsg(5);
        }
    }

    /**
     * 获取所以字段名称
     *
     * @param string $app  项目名称
     * @param string $type 想要获取的字段类型(id,body,title),默认获取全部
     * @return array|string $fieldList
     * @throws XSException
     */
    public static function getFields ($app = '',$type = '')
    {
        $app  = self::getApp($app);
        $type = empty($type) ? 'all' : $type;
        $xun  = self::instance($app);
        if ($type == 'all') {
            $fieldList = $xun->allFields;
            foreach ($fieldList as $value) {
                $fieldList[$value->name] = $value->name;
            }
        } else {
            if (is_string($type)) {
                $type = self::setArray($type);
            }
            foreach ($type as $field) {
                $func = 'field'.ucfirst($field);
                if (is_object($xun->$func)) {
                    $fieldList[$xun->$func->name] = $xun->$func->name;
                } else {
                    self::errorMsg(6,array('field' => $field));
                }
            }
            if (count($fieldList) == 1) {
                $fieldList = array_shift($fieldList);
            }
        }
        return $fieldList;
    }

    /**
     * 立即刷新
     *
     * @param string $app
     */
    public static function flush ($app = '')
    {
        $app = self::getApp($app);
        self::index($app)->flushIndex();
    }

    public static function openBuffer($size = '')
    {
        self::$buffer = true;
        if (!empty($size) && $size > 0) {
            self::$bufferSize = $size;
        }
    }

    public static function closeBuffer()
    {
        if (self::$buffer) {
            self::$buffer = false;
            $app = self::getApp();
            self::index($app)->closeBuffer();
        }
    }

    /**
     * 按','分割成数组
     *
     * @param string $value
     * @return array|string
     */
    protected static function setArray ($value = '')
    {
        if (is_string($value) && !empty($value)) {
            $value = explode(',',$value);
        }
        return $value;
    }

    /**
     * 抛出异常
     *
     * @param int $code
     * @param array $ext
     * @throws XSException
     */
    protected static function errorMsg($code = 0,$ext = array())
    {
        $msg = '';
        switch ($code) {
            case 1:
                $msg = '项目名称填写错误';
                break;
            case 2:
                $msg = '没有这个方法';
                break;
            case 3:
                $msg = '删除的字段不存在';
                break;
            case 4:
                $msg = '字段存储错误';
                break;
            case 5:
                $msg = '数据格式错误';
                break;
            case 6:
                $msg = '没有'.$ext['field'].'字段';
                break;
        }
        throw new XSException($msg);
        exit;
    }
}