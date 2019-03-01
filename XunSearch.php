<?php
require dirname(__FILE__) . '/lib/XS.php';

/**
 * Class XunSearch 迅搜 扩展类
 *
 * 说明:
 * 需要根据项目目录引用XS.php
 * 项目名称为配置文件里的project.name
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
    private static $app = '';

    /**
     * @var bool 开启缓存
     */
    private static $buffer = false;

    /**
     * @var int 缓存大小
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
        self::$app = $app;
    }

    /**
     * 初始化(单例)
     *
     * @param string $app 项目名称
     * @return mixed
     */
    public static function instance($app = '')
    {
        if (!isset(self::$xs[$app])) {
            self::$xs[$app] = new self($app);
        }
        self::$app = $app;
        return self::$xs[$app];

    }

    public static function getApp($app = '')
    {
        return !empty($app) ? $app : (!empty(self::$app) ? self::$app : '');
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        $appParam = !empty($arguments[0]) ? $arguments[0] : '';
        $app = self::getApp($appParam);
        $xun = self::instance($app);
        if (in_array($name, array('search', 'index', 'scws'))) {
            if (!isset(self::$ojb[$app][$name])) {
                if ($name == 'scws') {
                    self::$ojb[$app][$name] = new XSTokenizerScws();
                } else {
                    self::$ojb[$app][$name] = $xun->$name;
                }
            }
            return self::$ojb[$app][$name];
        } else if (in_array($name, array('hot', 'suggest', 'related', 'corrected'))) {
            switch ($name) {
                case 'hot':   //no break
                case 'corrected':   //no break
                case 'related':
                    $func = 'get' . ucfirst($name) . 'Query';
                    break;
                case 'suggest':
                    $func = 'getExpandedQuery';
                    break;
                default:
                    $func = 'getCorrectedQuery';
                    break;
            }
            $keyWords = $arguments[1];
            if ($name == 'related') {
                $list = self::search()->$func($keyWords);
            } else {
                $list = self::search()->$func($keyWords);
            }
            return $list;
        } else if (in_array($name, array('doc'))) {
            switch ($name) {
                case 'doc':
                    $object = new XSDocument();
                    break;
            }
            return $object;
        } else {
            return self::errorMsg(2);
        }
    }

    /**
     * 搜索建议列表
     *
     * @param string $app 项目名称
     * @param string $keyWord 搜索词
     * @return mixed
     */
    public static function suggestList($app = '', $keyWord = '')
    {
        return self::suggest($app, $keyWord);
    }

    /**
     * @param string $keyWord
     * @return mixed
     */
    public function getSuggest($keyWord = '')
    {
        return self::suggestList(self::$app, $keyWord);
    }

    /**
     * 相关搜索列表
     *
     * @param string $app 项目名称
     * @param string $keyWord 搜索词
     * @return mixed
     */
    public static function relatedList($app = '', $keyWord = '')
    {
        return self::related($app, $keyWord);
    }

    /**
     * @param string $keyWord
     * @return mixed
     */
    public function getRelated($keyWord = '')
    {
        return self::relatedList(self::$app, $keyWord);
    }

    /**
     * 搜索热词列表
     *
     * @param string $app 项目名称
     * @param string $keyWord 搜索词
     * @return mixed
     */
    public static function hotList($app = '', $keyWord = '')
    {
        return self::hot($app, $keyWord);
    }

    /**
     * @param string $keyWord
     * @return mixed
     */
    public function getHot($keyWord = '')
    {
        return self::hotList(self::$app, $keyWord);
    }

    /**
     * 搜索纠错列表
     *
     * @param string $app 项目名称
     * @param string $keyWord 搜索词
     * @return mixed
     */
    public static function correctedList($app = '', $keyWord = '')
    {
        return self::corrected($app, $keyWord);
    }

    /**
     * @param string $keyWord
     * @return mixed
     */
    public function getCorrected($keyWord = '')
    {
        return self::correctedList(self::$app, $keyWord);
    }

    /**
     * 获取搜索结果
     * @param string $app 项目名称
     * @param string $keyWord 搜索关键字
     * @return array|string 返回数据结果和总数|错误信息
     */
    public static function listing($app = '', $keyWord = '', $filter = array())
    {
        $search = self::search($app);
        $search->setQuery($keyWord);
        $fields = self::getFields();
        $result = array();
        if (is_array($fields) && !empty($fields)) {
            self::flushLog();
            self::parseFilter($filter);
            $searchList = $search->search(null, false);
            foreach ($searchList as $k => $v) {
                foreach ($fields as $field) {
                    $result['list'][$k][$field] = $v->$field;
                }
                $result['list'][$k]['percent'] = $v->percent() . '%';
                $result['list'][$k]['rank'] = $v->rank();
                $result['list'][$k]['weight'] = $v->weight();
                $result['list'][$k]['ccount'] = $v->ccount();
            }
            $result['count'] = $search->getLastCount();
            if ($result['count'] == 0) {
                //搜索纠错
                $result['corrected'] = self::corrected($app, $keyWord);
                //搜索建议
                $result['suggest'] = self::suggest($app, $keyWord);
                //热门搜索
                //$result['hot'] = self::hot($app,$keyWord);
                //相关搜索
                //$result['related'] = self::related($app, $keyWord);
                $result['result'] = 0;

                /**
                 * 如果没搜到结果,按照建议词的第一个或者纠错第一个
                 */
                $word = '';
                if (!empty($result['corrected'])) {
                    $word = $result['corrected'][0];
                } else if (!empty($result['suggest'])) {
                    $word = $result['suggest'][0];
                } else {
                    //都没有结果直接返回空
                    self::log($keyWord, 'SEARCH', $result['count']);
                    return $result;
                }
                $result = array_merge(
                    $result,
                    self::listing($app, $word, $filter)
                );
            }
            self::log($keyWord, 'SEARCH', $result['count']);
            return $result;
        } else {
            return self::errorMsg(7);
        }
    }

    /**
     * @param string $keyWord
     * @param array $filter
     * @return array
     */
    public function getList($keyWord = '', $filter = array())
    {
        return self::listing(self::$app, $keyWord, $filter);
    }

    /**
     * 过滤规则
     *
     * @param array $filter 过滤列表
     *   num 取出条数
     *   offset 偏移量
     *   fuzzy 模糊查询
     *   charset 字符集
     *   cutOf 过滤(以下的)值
     *   addWeight 增加权重
     */
    public static function parseFilter($filter = array())
    {
        $filter = array_merge(array(
            'num' => 100,
            'offset' => 0,
            'charset' => 'utf-8',
        ), $filter);
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
                    break;
                case "weight" :
                    if (is_string($filter['weight'])) {
                        $weight = self::setArray($filter['weight']);
                    } else {
                        $weight = $filter['weight'];
                    }
                    list($fields, $keyWord) = $weight;
                    $search->addWeight($fields, $keyWord);
            }
        }
    }

    /**
     * 异步删除
     *
     * @param string $app 项目名称
     * @param array|string $key 删除的键值 array('123',456)|$str = '123,456';
     * @param string $field 指定字段(按照配置文件字段指定)
     * @return bool 正确返回true
     */
    public static function del($app = '', $key, $field = '')
    {
        $index = self::index($app);
        $keyArr = self::setArray($key);
        if (empty($field)) {
            $index->del($keyArr);
        } else {
            $fields = self::getFields();
            if (in_array($field, $fields)) {
                $index->del($keyArr, $field);
            } else {
                return self::errorMsg(3);
            }
        }
        return true;
    }

    /**
     * @param $key
     * @param string $field
     * @return bool
     */
    public function delete($key, $field = '')
    {
        return self::del(self::$app, $key, $field);
    }

    /**
     * 立即删除
     *
     * @param string $app 项目名称
     * @param array|string $key 删除的键值 array('123',456)|$str = '123,456';
     * @param string $field 指定字段(按照配置文件字段指定)
     */
    public static function flushDel($app = '', $key, $field = '')
    {
        self::del($app, $key, $field);
        self::flush();
    }

    /**
     * @param $key
     * @param string $field
     */
    public function flushDelete($key, $field = '')
    {
        self::flushDel($key, $field = '');
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
     * 清空索引
     */
    public function cleanAll()
    {
        self::clean(self::$app);
    }

    /**
     * 新增/更新索引(自动判断)
     *
     * @param string $app 项目名称
     * @param array $data 添加数据
     * @param array $option 设置选项
     *   flush 是否立即刷新
     *   method:数据存储方法
     *     update 强制使用更新方法
     *     add 强制使用增加方法
     *     default 自动判断
     * @return bool
     */
    public static function store($app = '', $data, $option = array())
    {
        if (is_array($data) && !empty($data)) {
            $fields = self::getFields($app);
            $diff = array_diff_key($fields, $data);
            if (empty($diff)) {
                $doc = self::doc();
                $index = self::index();
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
                ), $option);
                extract($option);
                $priKey = self::getFields($app, 'id');
                switch ($method) {
                    case 'update': //no break
                    case 'add':
                        $index->$method($doc);
                        break;
                    case 'default':  //no break
                    default:
                        $search = self::search();
                        self::flush();
                        if (!empty($data[$priKey]) && is_numeric($data[$priKey])) {
                            $count = $search->count($priKey . ':' . $data[$priKey]);
                            if ($count > 0) {
                                $func = 'update';
                            } else {
                                $func = 'add';
                            }
                            $index->$func($doc);
                        } else {
                            return self::errorMsg(5);
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
                self::log($priKey . ':' . $data[$priKey], strtoupper($method));
                return true;
            } else {
                return self::errorMsg(4);
            }
        } else {
            return self::errorMsg(5);
        }
    }

    /**
     * @param array $data
     * @param array $option
     * @return bool
     */
    public function save($data, $option = array())
    {
        return self::store(self::$app, $data, $option);
    }

    /**
     * 分词
     *
     * @param string $app 项目名称
     * @param string $words 关键词
     * @return string ,分割的结果
     */
    public static function getScwsWord($app = '', $words = '')
    {
        if (func_num_args() == 1) {
            $words = $app;
            $app = self::getApp();
        } else {
            $app = self::getApp($app);
        }
        $scws = self::scws($app);
        $wordArr = $scws->getResult($words);
        $wordStr = '';
        foreach ($wordArr as $word) {
            $wordStr .= $word['word'] . ',';
        }
        return $wordStr;
    }

    /**
     * 索引训练
     *
     * @param string $app 项目名称
     * @param string $keyword 关键词
     * @return bool
     */
    public static function training($app = '', $keyword = '')
    {
        set_time_limit(3000);
        $app = self::getApp($app);
        $keyword = self::getScwsWord($app, $keyword);
        $wordArr = self::setArray(rtrim($keyword, ','));
        $search = self::search($app);
        foreach ($wordArr as $word) {
            for ($i = 0; $i <= 50; $i++) {
                $search->setQuery($word);
                $search->search(null, false);
                self::log($word, 'SEARCH', $search->getLastCount());
            }
            self::flushLog();
        }
        $keyword = str_replace(',', '', $keyword);
        for ($j = 0; $j <= 50; $j++) {
            $search->search($keyword);
            self::log($keyword, 'SEARCH', $search->getLastCount());
        }
        self::flushLog();
        set_time_limit(ini_get('max_execution_time'));
        return true;
    }

    /**
     * 获取字段名称
     *
     * @param string $app 项目名称
     * @param string $type 想要获取的字段类型(id,body,title),默认获取全部
     * @return array|string $fieldList
     */
    public static function getFields($app = '', $type = '')
    {
        $app = self::getApp($app);
        $type = empty($type) ? 'all' : $type;
        $xun = self::instance($app);
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
                $func = 'field' . ucfirst($field);
                if (is_object($xun->$func)) {
                    $fieldList[$xun->$func->name] = $xun->$func->name;
                } else {
                    return self::errorMsg(6, array('field' => $field));
                }
            }
            if (count($fieldList) == 1) {
                $fieldList = array_shift($fieldList);
            }
        }
        return $fieldList;
    }

    /**
     * 刷新日志(静态)
     *
     * @param string $app
     */
    public static function flushLog($app = '')
    {
        $app = self::getApp($app);
        self::log('flush', 'FLUSH');
        self::index($app)->flushLogging();
    }

    /**
     * 刷新日志
     */
    public function logFlush()
    {
        self::flushLog(self::$app);
    }

    /**
     * 立即刷新
     *
     * @param string $app
     */
    public static function flush($app = '')
    {
        $app = self::getApp($app);
        self::index($app)->flushIndex();
    }

    /**
     * 立即刷新
     */
    public function appFlush()
    {
        self::flush(self::$app);
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
    protected static function setArray($value = '')
    {
        if (is_string($value) && !empty($value)) {
            $value = explode(',', $value);
        }
        return $value;
    }

    /**
     * 错误信息
     *
     * @param int $code
     * @param array $ext
     * @return string
     */
    protected static function errorMsg($code = 0, $ext = array())
    {
        switch ($code) {
            case 1:
                $msg = '项目名称填写错误';
                break;
            case 2:
                $msg = '方法不存在';
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
                $msg = '没有' . $ext['field'] . '字段';
                break;
            case 7:
                $msg = '字段不存在';
                break;
        }
        //throw new XSException($msg);
        self::log($msg, 'ERROR');
        return $msg;
    }

    private static function log($info, $type, $content = '')
    {
        if (PHP_OS == 'WINNT') {
            $dir = 'd:/XunSearch.log';
        } else {
            $dir = '/home/XunSearch/XunSearch.log';
        }
        $fp = fopen($dir, 'a');
        $message = date('Y-m-d H:i:s') . '--' . $info . '--' . $type . '--' . $content . "\r\n";
        fwrite($fp, $message);
        fclose($fp);
    }
}