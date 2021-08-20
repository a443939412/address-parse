<?php

namespace Zifan\AddressParser;

use Symfony\Component\Process\Exception\RuntimeException;
use Zifan\LaravelAddressParser\Events\AfterFailedParsing;

/**
 * Class AddressParser
 * @link https://www.cnblogs.com/gqx-html/p/10790464.html
 * @link https://github.com/pupuk/address-smart-parse
 * @link https://ai.baidu.com/tech/nlp_apply/address?track=cp:ainsem|pf:pc|pp:chanpin-NLP|pu:NLP-dizhishibie|ci:|kw:10014588 #“百度地址识别：实现了姓名、街道维度的提取
 */
class AddressParser
{
    /**
     * The AddressParser version.
     *
     * @var string
     */
    const VERSION = '2.0.0';

    /**
     * @var array|\ArrayAccess
     */
    protected static $areas = [];

    /**
     * @var array
     */
    protected $config = [
        'dataProvider' => [
            'driver' => 'file'
        ]
    ];

    /**
     * AddressParser constructor.
     * @param array $options 配置项请参考 config 方法
     * @see config()
     */
    public function __construct(array $options = [])
    {
        $this->config($options);
    }

    /**
     * @param array $options Like: [
     *     'dataProvider' => [
     *         'driver' => 'file'
     *     ],
     *     'interference_words' => [],    // 干扰词
     *     'extra' => [                   // 额外提取字段
     *         'sub_district' => false,   // 村乡镇/街道（准确度低）
     *         'idn' => false,            // 身份证号
     *         'mobile' => false,         // 联系方式（手机号/座机号）
     *         'postcode' => false,       // 邮编
     *         'person' => false,         // 姓名（准确度低）
     *     ],
     *     'strict' => true,              // 是否对提取结果进行准确度校验
     * ]
     * @return $this
     */
    public function config(array $options)
    {
        $this->config = array_replace_recursive($this->config, $options);

        return $this;
    }

    /**
     * @return array|\ArrayAccess Like: [
     *     0 => [
     *         'id' => 1,
     *         'name' => '北京',
     *         'parent_id' => 0,
     *         'level' => 1,
     *         'children' => [
     *             [
     *                 'id' => 33, 'name' => '北京市', 'parent_id' => 1, 'level' => 2,
     *                 'children' => [...]
     *             ]
     *         ]
     *     ],
     *     ...
     * ]
     */
    public function getAreas()
    {
        if (empty(static::$areas)) {
            static::$areas = $this->dateProvider()->toTree();
        }

        // Validate data tree
        $first = current(static::$areas);
        if (!isset($first['name']) ||
            !isset($first['children']) ||
            !isset(current($first['children'])['name'])) {
            throw new RuntimeException('驱动提供的数据，不符合插件要求的树形结构！');
        }

        return static::$areas;
    }

    /**
     * @return DataProviderInterface
     */
    protected function dateProvider(): DataProviderInterface
    {
        $driver = $this->config['dataProvider'] ?? null;

        if (empty($driver['driver'])) {
            throw new RuntimeException('未设置数据驱动，请正确配置 dataProvider.driver');
        }

        return (new DataProvider)->resolve($driver);
    }

    /**
     * @return $this
     */
    public function release()
    {
        static::$areas = null;

        return $this;
    }

    /**
     * @param string $address
     * @return array
     */
    public function smart(string $address): array
    {
        // 排除干扰词：过滤掉收货地址中的常用说明字符
        if ($words = $this->config['interference_words'] ?? null) {
            $replace = array_fill(0, count($words), ' ');
            $address = str_replace($words, $replace, $address);
        }

        $result = $this->matchIfRegular($address)
            ?: $this->matchViaRegex($address)
            ?: $this->matchFuzzy($address);

        $result = $result
            ? array_combine(['province', 'city', 'district', 'address'], $result)
            : array_fill_keys(['province', 'city', 'district'], null) + ['address' => $address];

        if ($this->config['strict'] ?? false) {
            $result = $this->correct(...array_values($result));
        }

        // 识别失败
        if (empty($result['province']) || empty($result['city'])) {
            event(new AfterFailedParsing(func_get_args()[0], $result['address'])); // $result = event(...);
        }

        if ($extra = array_filter($this->config['extra'] ?? [])) {
            $extra = $this->matchExtra($result['address'] ?? $address, $extra);
            $result = $result ? array_merge($result, $extra) : $extra;
        }

        return $result;
    }

    /**
     * 规则的地址匹配
     * @param string $address
     * @return array|false
     * @internal 何为规则的地址，即省、市、区由特殊符号分隔开。
     * 省级地名最大字符长度：8 （新疆维吾尔自治区）
     * 市级地名最大字符长度：11（黔西南布依族苗族自治州、克孜勒苏柯尔克孜自治州）
     * Mysql _>
     * SELECT * FROM `areas` where level in (1, 2) and CHAR_LENGTH(`name`) >= 11;
     * 1) length()： 单位是字节，UTF8编码下一个汉字占三个字节，一个数字或字母占一个字节；GBK
     *    编码下一个汉字占两个字节，一个数字或字母占一个字节。
     * 2) char_length()：单位为字符，不管汉字还是数字或者是字母都算是一个字符。
     * 3) character_length(): 同 char_length
     */
    protected function matchIfRegular($address)
    {
        $result = preg_split('/[\s\.，,]+/u', $address, 4);

        if ($result === false ||
            count($result) < 3 ||
            mb_strlen(current($result)) > 8 || // 只检查省、市级地名长度，区级划分可能不存在
            mb_strlen(next($result)) > 11) {   // Check province_city_level_region_max_length
            return false;
        }

        if (count($result) < 4) {
            array_splice($result, 2, 0, '');
        }

        return $result;
    }

    /**
     * 通过正则匹配
     * @param string $address
     * @return array|false
     * @internal 省、市、区最长最短字数：（不计算自治区、直辖市、特别行政区）
     * 省最短：3个字
     * 省最长：4个字（黑龙江省）
     * 市最短：3个字
     * 市最长：5个字（呼和浩特市、鄂尔多斯市、呼伦贝尔市等）
     * 区级最短：2个字（东区、西区、矿区、郊区、赵县、沛县等）
     * 区级最长：15个字（双江拉祜族佤族布朗族傣族自治县、积石山保安族东乡族撒拉族自治县）
     * Mysql_>
     * SELECT max(CHAR_LENGTH(`name`)) FROM `areas` WHERE `name` LIKE '%省' and level = 1;
     * SELECT * FROM `areas` WHERE `name` LIKE '%省' and level = 1 and CHAR_LENGTH(`name`) > 3;
     */
    protected function matchViaRegex(string $address)
    {
        // ([\x{4e00}-\x{9fa5}]+省)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]+市)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{2,5}[市县区旗])?([^市县区旗]*$)
        if (preg_match("#^([\x{4e00}-\x{9fa5}]{2,3}省)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{2,4}市)(.*)$#Uu", $address, $match) == false) {
            return false;
        }

        array_shift($match);
        // 排除干扰字符/词
        $address = str_replace($chi = ['小区', '校区', '园区'], $rep = ['{小QU}', '{校QU}', '{园QU}'], end($match));

        if (preg_match("#^[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{1,12}(?:自治县|县|市|区|旗))(.*)$#Uu", $address, $match2) == false) {
            array_splice($match, 2, 0, '');
            return $match;
        }

        array_shift($match2);
        array_pop($match);
        array_push($match, ...$match2);

        $address = end($match);
        $match[key($match)] = str_replace($rep, $chi, $address);

        return $match;
    }

    /**
     * @deprecated 模糊匹配
     * @param string $address
     * @return array|false 省，市，区，街道地址
     * @internal 反向查找：从区级 -> 市级 -> 省级
     *
     * @author pupuk<pujiexuan@gmail.com>
     */
    protected function matchFuzzy($address)
    {
        $a1 = ''; // province
        $a2 = ''; // city
        $a3 = ''; // district
        $origin = $address;

        $address = str_replace([' ', ',', /*'自治区', '自治州',*/ '小区', '校区', '园区'], ['', '', /*'省', '州',*/ '' , ''], $address);

        $refPos  = floor((mb_strlen($address) / 3) * 2);
        $xianPos = mb_strpos($address, '县');
        $quPos   = mb_strpos($address, '区');
        $qiPos   = mb_strpos($address, '旗');

        if ($xianPos !== false && $xianPos < $refPos ||
            $quPos   !== false && $quPos   < $refPos ||
            $qiPos   !== false && $qiPos   < $refPos) {

            if (mb_strstr($address, '旗')) {
                $deep3_keyword_pos = $qiPos;
                $a3 = mb_substr($address, $deep3_keyword_pos - 1, 2);
            }
            if (mb_strstr($address, '区')) {
                $deep3_keyword_pos = $quPos;

                if (mb_strstr($address, '市')) {
                    $city_pos = mb_strpos($address, '市');
                    $zone_pos = mb_strpos($address, '区');
                    $a3 = mb_substr($address, $city_pos + 1, $zone_pos - $city_pos);
                } else {
                    $a3 = mb_substr($address, $deep3_keyword_pos - 2, 3);
                }
            }
            if (mb_strstr($address, '县')) {
                $deep3_keyword_pos = $xianPos;

                if (mb_strstr($address, '市')) {
                    $city_pos = mb_strpos($address, '市');
                    $zone_pos = mb_strpos($address, '县');
                    $a3 = mb_substr($address, $city_pos + 1, $zone_pos - $city_pos);
                } else {
                    //考虑形如【甘肃省东乡族自治县布楞沟村1号】的情况
                    if (mb_strstr($address, '自治县')) {
                        $a3 = mb_substr($address, $deep3_keyword_pos - 6, 7);

                        $firstWord = mb_substr($a3, 0, 1);
                        if (in_array($firstWord, ['省', '市', '州'])) {
                            $a3 = mb_substr($a3, 1);

                            if ($firstWord !== '市') {
                                $a1 = mb_strstr($address, $a3, true);
                            }
                        }
                    } else {
                        $a3 = mb_substr($address, $deep3_keyword_pos - 2, 3);
                    }
                }
            }
            $address = mb_substr($origin, $deep3_keyword_pos + 1);
        } else {
            $shiRPos = mb_strripos($address, '市');
            if ($shiRPos !== false) {

                if (mb_substr_count($address, '市') == 1) {
                    $deep3_keyword_pos = $shiRPos;
                    $a3 = mb_substr($address, $deep3_keyword_pos - 2, 3);
                    $address = mb_substr($origin, $deep3_keyword_pos + 1);
                } else if (mb_substr_count($address, '市') >= 2) {
                    $deep3_keyword_pos = mb_strripos($address, '市');
                    $a3 = mb_substr($address, $deep3_keyword_pos - 2, 3);
                    $address = mb_substr($origin, $deep3_keyword_pos + 1);
                }
            } else {

                $a3 = '';
                //$address = $address;
            }
        }

        if (mb_strrpos($address, '市') ||
            mb_strstr($address, '盟') ||
            mb_strstr($address, '州'))
        {
            if ($pos = mb_strrpos($address, '市')) {
                $a2 = mb_substr($address, $pos - 2, 3);
            }
            elseif ($pos = mb_strrpos($address, '盟')) {
                $a2 = mb_substr($address, $pos - 2, 3);
            }
            elseif ($pos = mb_strrpos($address, '州')) {
                $a2 = ($ziZhiPos = mb_strrpos($address, '自治州')) !== false
                    ? mb_substr($address, $ziZhiPos - 4, 5)
                    : mb_substr($address, $pos - 2, 3);
            }
        }

        $result = compact('a1', 'a2', 'a3', 'address');

        return count(array_filter($result)) <= 2 ? false : $result;
    }

    /**
     * 检查并校正省、市、区地址名称
     *
     * @param string $province
     * @param string $city
     * @param string $district
     * @param string $address
     * @return array  省，市，区：['province' => 'xxx', 'city' => 'yyy', 'district' => 'zzz', 'address' => 'aaa']
     */
    protected function correct($province, $city, $district, $address = ''): array
    {
        $areas = $this->getAreas();

        if ($province) {
            $result = compact('province', 'city', 'district');
            $this->recursiveCorrect($areas, $result);
        }

        elseif ($city) {
            $result = compact('city', 'district');
            $this->correctFromLevel2($areas, $result);
        }
        else {
            $result = [];
        }

        // 识别结果错误
        if (empty($result['province']) || empty($result['city']) || empty($result['district'])) {
            $this->correctVerbatim($result, func_get_args());
        }

        return $result + ['address' => $address];
    }

    /**
     * 递归矫正（顺序）
     * @param array|\ArrayAccess $areas
     * @param array $result
     */
    protected function recursiveCorrect($areas, array &$result)
    {
        $key = key($result);
        $value = current($result);

        $result[$key] = $value && ($area = $this->lookup($areas, $value))
            ? $area['name'] : null;

        if (next($result)) {
            $this->recursiveCorrect($area['children'] ?? [], $result);
        }
    }

    /**
     * @param array|\ArrayAccess $areas
     * @param array $result
     *
     * @internal SELECT * FROM `areas` WHERE  level = 2 GROUP BY `name` HAVING COUNT(id) > 1; 空记录，说明市级地名是全国唯一的
     */
    protected function correctFromLevel2($areas, array &$result)
    {
        $city = current($result);
        $district = next($result);

        foreach ($areas as $area) {
            $cityArea = $this->lookup($area['children'] ?? [], $city);

            if ($cityArea) {
                if ($district) {
                    $districtArea = $this->lookup($cityArea['children'] ?? [], $district);
                }

                $result = ['province' => $area['name'], 'city' => $cityArea['name'], 'district' => $districtArea['name'] ?? null];

                if ($cityArea['name'] === $city || isset($districtArea)) {
                    return;
                }
            }
        }

        $result = []; // unset($result);
    }

    /**
     * @param array|\ArrayAccess $areas
     * @param string $target
     * @return array|null
     */
    protected function lookup($areas, string $target): ?array
    {
        foreach ($areas as $area) {
            if (mb_strpos($area['name'], $target) !== false) {
                return $area;
            }
        }

        return null;
    }

    protected function correctVerbatim(array &$result, array $origin)
    {
        //@FIXME 甘肃省东乡族自治县布楞沟村1号  渝北区渝北中学51200街道  成都贝尔通讯实业有限公司
        //@FIXME goto end;
        //@TODO seed
        return array_fill_keys(['province', 'city', 'district', 'address'], null);
        if (empty($result['province'])) {
            $address = implode('', $origin);
            $tmp = mb_substr($address, 0, 2);
            //$this->recursiveLookup(, $tmp);
        }
    }

    protected function recursiveLookup($areas, string $target, $level)
    {
        foreach ($areas as $area) {
            if (mb_strpos($area['name'], $target) !== false) {
                return $area;
            }
        }
    }

    /**
     * 匹配额外字段：乡镇/街道，手机号(座机)，身份证号，姓名，邮编等信息
     *
     * @param string $string
     * @param array $extra
     * @return array
     */
    protected function matchExtra(string $string, array $extra): array
    {
        $compose = array_fill_keys(array_keys($extra), null);

        // 提取村乡镇/街道
        if (isset($extra['sub_district']) &&
            preg_match('/^[\x{4e00}-\x{9fa5}]+(?:街道|镇|村|乡)/Uu', $string, $match)) {
            $compose['sub_district'] = $match[0];
            $string = str_replace($match[0], ' ', $string);
        }

        // 提取中国境内身份证号码
        if (isset($extra['idn']) &&
            preg_match('/\d{18}|\d{17}X/i', $string, $match)) {
            $compose['idn'] = strtoupper($match[0]);
            $string = str_replace($match[0], ' ', $string);
        }

        // 提取联系方式
        if (isset($extra['mobile'])) {
            // 去除手机号码中的短横线 如136-3333-6666 主要针对苹果手机
            $string = preg_replace('/0-|0?(\d{3})-(\d{4})-(\d{4})/', '$1$2$3', $string);
            // /\d{7,11}|\d{3,4}-\d{6,8}/
            if (preg_match('/1[0-9]{10}|(\d{3,4}\-)?\d{8}(\-\d{1,})?/U', $string, $match)) {
                $compose['mobile'] = $match[0];
                $string = str_replace($match[0], ' ', $string);
            }
        }

        // 提取邮编
        if (isset($extra['postcode']) &&
            preg_match('/\d{6}/U', $string, $match)) {
            $compose['postcode'] = $match[0];
            $string = str_replace($match[0], ' ', $string);
        }

        // 提取姓名（最长名字貌似由15个字，不过只考虑一般情况提高准确性，取10个，TODO可以提取到配置文件）
        if (isset($extra['person']) &&
            preg_match('/(?:[一二三四五六七八九\d+](?:室|单元|号楼|期|弄|号|幢|栋)\d*)+ *([^一二三四五六七八九 室期弄号幢栋|单元|号楼|商铺|档口|A-Za-z0-9_#！!@（\(]{2,10}) *(?:\d{11})?$/Uu', $string , $match)) {
            $compose['person'] = $match[1];
            $string = str_replace($compose['person'], ' ', $string);
        }
        /*if (isset($extra['name']) &&
            !empty($result = preg_split('/\s+/', $string))) {
            // 按照空格切分后，片面的判断最短的为姓名（不是基于自然语言分析，只是采取统计学上高概率的方案）
            $compose['name'] = $result[0];
            foreach ($result as $value) {
                if (mb_strlen($value) < mb_strlen($compose['name'])) {
                    $compose['name'] = $value;
                }
            }
            // $string = trim(str_replace($compose['name'], '', $string));
        }*/

        $compose['address'] = preg_replace('/\s+/', ' ', trim($string));

        return $compose;
    }
}