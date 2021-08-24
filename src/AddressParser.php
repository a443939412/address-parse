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

        if (isset($first['id'], $first['name'], $first['children'],
            current($first['children'])['id'], current($first['children'])['name'])) {
            return static::$areas;
        }

        throw new RuntimeException('驱动提供的数据，不符合插件要求的树形结构！');
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

        $result = $this->performParse($address);

        // Dispatch event
        if (empty($result['province']) || empty($result['city'])) {
            event(new AfterFailedParsing(func_get_args()[0], $result['address'])); // $result = event(...);
        }

        // Parse extra fields
        if ($extra = array_filter($this->config['extra'] ?? [])) {
            $extra = $this->matchExtra($result['address'] ?? $address, $extra);
            $result = $result ? array_merge($result, $extra) : $extra;
        }

        $result['address'] = preg_replace('/\s+/', ' ', $result['address']);

        return $result;
    }

    /**
     * @param string $address
     * @return array
     * @internal Verbatim: 逐字逐句
     */
    protected function performParse(string $address): array
    {
        $result = $this->extractIfRegular($address)
            ?: $this->extractViaRegex($address);

        $strict = $this->config['strict'] ?? false;

        if ($result && count($result) >= 4 && $strict) {
            $result = $this->correct(...$result); // array_values($result)
        }

        if (!$result && ($result = $this->extractReverseFuzzy($address)) && $strict) {
            $result = $this->correctReverse(...array_values($result));
        }

        // array_combine(['province', 'city', 'district', 'address'], $result)
        return $result ?: array_fill_keys(['province', 'city', 'district'], null) + ['address' => $address];
    }

    /**
     * 规则的地址提取
     * @param string $address
     * @return array|null
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
    protected function extractIfRegular($address): ?array
    {
        $result = preg_split('/[\s\.，,]+/u', $address, 4);

        if ($result === false ||
            count($result) < 3 ||
            mb_strlen(current($result)) > 8 || // 只检查省、市级地名长度，区级划分可能不存在
            mb_strlen(next($result)) > 11) {   // Check province_city_level_region_max_length
            return null;
        }

        foreach (array_slice($result, 0, 2) as $value) {
            if (preg_match('#[^\x{4e00}-\x{9fa5}]#u', $value)) {
                return null;
            }
        }

        if (count($result) < 4) {
            array_splice($result, 2, 0, '');
        }

        return $result;
    }

    /**
     * 通过正则匹配
     * @param string $address
     * @return array|null
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
    protected function extractViaRegex(string $address): ?array
    {
        // ([\x{4e00}-\x{9fa5}]+省)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]+市)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{2,5}[市县区旗])?([^市县区旗]*$)
        if (preg_match("#([\x{4e00}-\x{9fa5}]{2,3}省)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{2,4}市)(.*)$#Uu", $address, $match) == false) {
            return null;
        }

        $left = str_replace(array_shift($match), ' ', $address);
        // 排除干扰字符/词
        $address = str_replace($chi = ['小区', '校区', '园区'], $rep = ['{小QU}', '{校QU}', '{园QU}'], end($match));

        if (preg_match("#^[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{1,12}(?:自治县|县|市|区|旗))(.*)$#Uu", $address, $match2) == false) {
            array_splice($match, 2, 0, '');
        } else {
            array_shift($match2);
            array_pop($match);
            array_push($match, ...$match2);
        }

        $address = $match2 ? str_replace($rep, $chi, end($match)) : end($match);
        $match[key($match)] = $left . $address;

        return $match;
    }

    /**
     * “省级地址”提取成功的前提下进行校正（即参数 $province 不能为空）
     *
     * @param string|null $province
     * @param string|null$city
     * @param string|null $district
     * @param string $address
     * @return array|false
     */
    protected function correct($province, $city, $district, $address = '')
    {
        $areas = $this->getAreas();

        $province = $this->lookup($areas, $province);

        if (!$province) {
            return false;
        }

        $city = $this->matchCity($province['children'] ?? [], $city, $districtArea);

        if (!$city) {
            return false;
        }

        if (!isset($districtArea)) {
            $districtArea = $this->matchDistrict($city['children'] ?? [], $district, $address);
        }

        return [
            'province'      => $province['name'],
            'province_id'   => $province['id'],
            'city'          => $city['name'],
            'city_id'       => $city['id'],
            'district'      => $districtArea['name'] ?? null,
            'district_id'   => $districtArea['id'] ?? null,
            'address'       => $address,
        ];
    }

    /**
     * 在指定“省级”区域下查找市级记录
     * @param array $cities
     * @param string $target
     * @param array|null $districtArea
     * @return array|false
     *
     * @internal 市级地名全国唯一：（以下查询空记录）
     * SELECT * FROM `areas` WHERE  level = 2 GROUP BY `name` HAVING COUNT(id) > 1;
     */
    protected function matchCity(array $cities, string $target, &$districtArea)
    {
        $districtArea = null;

        if ($city = $this->lookup($cities, $target)) {
            return $city;
        }

        // Can recursive lookup
        foreach ($cities as $city) {
            if ($districtArea = $this->lookup($city['children'] ?? [], $target)) {
                return $city;
            }
        }

        return false;
    }

    /**
     * 在指定“市级”区域下查找区级记录
     *
     * @param array $districts
     * @param string $target
     * @param string $address
     * @return array|false;
     */
    protected function matchDistrict(array $districts, string $target = '', &$address)
    {
        $districtArea = $this->lookup($districts ?? [], $target ?: mb_substr($address, 0, 2));

        if (isset($districtArea) && $target == '') {
            $address = str_replace([$districtArea['name']/*, mb_substr($address, 0, 2)*/], '', $address);
        }
        elseif (!isset($districtArea) && $target) {
            $address = $target .' '. $address;
        }

        return $districtArea ?: false;
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

    /**
     * 逆向模糊提取
     * @param string $address
     * @return array|false 省，市，区，街道地址
     * @internal 反向查找：从区级 -> 市级 -> 省级
     *
     * @author pupuk<pujiexuan@gmail.com>
     */
    protected function extractReverseFuzzy($address)
    {
        $a1 = ''; // province
        $a2 = ''; // city
        $a3 = ''; // district

        $address = str_replace([' ', ',', '自治区', '自治州', '小区', '校区', '园区'], ['', '', '省', '州', '' , ''], $address);

        $refPos  = floor((mb_strlen($address) / 3) * 2);
        $xianPos = mb_strpos($address, '县');
        $quPos   = mb_strpos($address, '区');
        $qiPos   = mb_strpos($address, '旗');


        if ($xianPos !== false && $xianPos < $refPos ||
            $quPos   !== false && $quPos   < $refPos ||
            $qiPos   !== false && $qiPos   < $refPos) {

            if ($qiPos) {
                $a3 = mb_substr($address, $qiPos - 1, 2);
            }
            if ($quPos) {
                $shiPos  = mb_strpos(mb_substr($address, 0, $quPos - 1), '市');
                $a3 = $shiPos ? mb_substr($address, $shiPos + 1, $quPos - $shiPos) : mb_substr($address, $quPos - 2, 3);
            }
            if ($xianPos) {
                if ($shiPos  = mb_strpos(mb_substr($address, 0, $xianPos - 1), '市')) {
                    $a3 = mb_substr($address, $shiPos + 1, $xianPos - $shiPos);
                } else {
                    //考虑形如【甘肃省东乡族自治县布楞沟村1号】的情况
                    if (mb_strpos($address, '自治县')) {
                        $a3 = mb_substr($address, $xianPos - 6, 7);

                        $firstWord = mb_substr($a3, 0, 1);
                        if (in_array($firstWord, ['省', '市', '州'])) {
                            $a3 = mb_substr($a3, 1);
                            /*
                            if ($firstWord !== '市') {
                                $a1 = mb_strstr($address, $a3, true);
                            }*/
                        }
                    } else {
                        $a3 = mb_substr($address, $xianPos - 2, 3); // 有两个字的县名：赵县，怎么办？
                    }
                }
            }

            //$street = mb_substr($origin, $deep3_keyword_pos + 1);
        }
        elseif (mb_substr_count($address, '市') >= 2) {
            $shiRPos = mb_strripos($address, '市');
            $a3 = mb_substr($address, $shiRPos - 2, 3);
            $a2 = mb_substr(mb_substr($address, 0, $shiRPos - 2), $shiRPos - 2, 3);
            //$street = mb_substr($origin, $shiRPos + 1);
        }

        if ($shiRPos = mb_strrpos($address, '市')) {
            $a2 = mb_substr($address, $shiRPos - 2, 3);
        }
        elseif ($menRPos = mb_strrpos($address, '盟')) {
            $a2 = mb_substr($address, $menRPos - 2, 3);
        }
        elseif ($zouRPos = mb_strrpos($address, '州')) {
            $a2 = ($ziZhiZouPos = mb_strrpos($address, '自治州')) !== false
                ? mb_substr($address, $ziZhiZouPos - 4, 5)
                : mb_substr($address, $zouRPos - 2, 3);
        }

        $result = compact('a1', 'a2', 'a3');

        return count(array_filter($result)) < 1
            ? false : $result + ['address' => func_get_args()[0]]; // 自治区、自治州被替换后要还原，所以 address 取原始值
    }

    /**
     * 检查并校正省、市、区地址名称
     *
     * @param string $province
     * @param string $city
     * @param string $district
     * @param string $address
     * @return array|false 省，市，区：['province' => 'xxx', 'city' => 'yyy', 'district' => 'zzz', 'address' => 'aaa']
     */
    protected function correctReverse(string $province, string $city, string $district, string $address = '')
    {
        $areas = $this->getAreas();

        $results = [];

        if ($district) {
            foreach ($areas as $provinceArea) {
                foreach ($provinceArea['children'] ?? [] as $cityArea) {
                    $districtArea = $this->lookup($cityArea['children'] ?? [], $district);
                    if ($districtArea) {
                        $results[] = [
                            'province'      => $provinceArea['name'],
                            'province_id'   => $provinceArea['id'],
                            'city'          => $cityArea['name'],
                            'city_id'       => $cityArea['id'],
                            'district'      => $districtArea['name'],
                            'district_id'   => $districtArea['id'],
                            'address'       => $address,
                        ];
                    }
                }
            }
        }
        elseif ($city) {
            foreach ($areas as $provinceArea) {
                $cityArea = $this->lookup($provinceArea['children'] ?? [], $city);

                if ($cityArea) {
                    $districtArea = $this->matchDistrict($cityArea['children'] ?? [], $district, $address);

                    $results[] = [
                        'province'      => $provinceArea['name'],
                        'province_id'   => $provinceArea['id'],
                        'city'          => $cityArea['name'],
                        'city_id'       => $cityArea['id'],
                        'district'      => $districtArea['name'] ?? null,
                        'district_id'   => $districtArea['id'] ?? null,
                        'address'       => $address,
                    ];
                }
            }
        }

        if (empty($results)) {
            return false;
        }

        if ($city) {
            foreach ($results as $result) {
                if (mb_strpos($result['city'], $city) !== false) {
                    goto end;
                }
            }
        }

        foreach ($results as $result) {
            if (mb_strpos($address, $result['province']) !== false) {
                goto end;
            }
        }

        $result = current($results);

        end: {
            /** @see \Illuminate\Support\Arr::only() 模仿实现 */
            $search = array_filter(array_intersect_key($result, array_flip(['district', 'city', 'province'])));
            $result['address'] = str_replace($search, ' ', $address);
        }

        return $result;
    }

    /**
     * 递归矫正（顺序）
     * @param array|\ArrayAccess $areas
     * @param array $result
     */
    /*protected function recursiveCorrect($areas, array &$result)
    {
        $key = key($result);
        $value = current($result);

        $result[$key] = $value && ($area = $this->lookup($areas, $value))
            ? $area['name'] : null;

        if (next($result)) {
            $this->recursiveCorrect($area['children'] ?? [], $result);
        }
    }*/

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

        $compose['address'] = $string;

        return $compose;
    }
}