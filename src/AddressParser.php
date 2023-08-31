<?php

namespace Zifan\AddressParser;

use Symfony\Component\Process\Exception\RuntimeException;

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
    const VERSION = '2.2.3';

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
        ],
        'enable_keywords_split' => false,
        'keywords' => [
            'person' => ['收货人', '收件人', '姓名'],
            'mobile' => ['手机号码', '手机', '联系方式', '电话号码', '电话'],
            //'address' => ['所在地区', '地址'] 不需要
            //'detail_address' => ['详细地址'] 不需要
        ]
    ];

    /**
     * 指定“县”干扰字
     *
     * @var string[]
     * @internal 如：'县城', '县政府'
     */
    protected $xian_interference_words = ['城', '政'];

    /**
     * 指定“区”干扰字
     *
     * @var string[]
     * @internal 如：'小区', '校区', '园区', '社区', '自治区', '投资区'
     * 经测试 str_replace 替换方式性能更优
     * $quString = preg_replace('#([小校园社发])区#Uu', '{$1QU}', $quString);
     * $quString = str_replace($chi = ['小区', '校区', '园区', '社区', '开发区'], $rep = ['{小QU}', '{校QU}', '{园QU}', '{社QU}', '{开发QU}'], $quString);
     */
    protected $qu_interference_words = ['小', '校', '园', '社', '治', '资']; // '地'：阿里地区

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
     * @param array $options Like: <pre>[
     *     'dataProvider' => [
     *         'driver' => 'file'            // 驱动，默认file，其它方式（如数据模型）可自行扩展
     *         'path' => null,               // 指定省市区数据文件，默认从插件config文件夹中读取
     *     ],
     *     'enable_keywords_split' => false, // 是否启用关键词分割（如淘宝、京东在复制收货地址时带固定格式）拼多多不带关键字，只是格式固定
     *     'keywords' => [                   // enable_keywords_split 为 true 时才生效
     *         'person' => ['收货人', '收件人', '姓名'],
     *         'mobile' => ['手机号码', '手机', '联系方式', '电话号码', '电话'],
     *     ],
     *     'extra' => [                      // 额外提取字段
     *         'sub_district' => false,      // 村乡镇/街道（准确度低）
     *         'idn' => false,               // 身份证号
     *         'mobile' => false,            // 联系方式（手机号/座机号）
     *         'postcode' => false,          // 邮编
     *         'person' => false,            // 姓名（准确度低）
     *     ],
     *     'strict' => true,                 // 是否对提取结果进行准确度校验、补齐
     * ]</pre>
     * @return $this
     */
    public function config(array $options): self
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
    public function release(): self
    {
        static::$areas = null;

        return $this;
    }

    /**
     * 解析省、市、区、详细地址等
     * @param string $address
     * @return array|false|string[]|null 提取失败返回null，校验失败返回false，解析成功返回数组
     */
    public function handle(string $address)
    {
        if ($address === '') {
            return null;
        }

        if ($this->config['enable_keywords_split'] ?? false) {
            $portion = $this->extractViaKeywords($address);
        }

        if ($result = $this->parse($address)) {
            $this->clearUpProvinceCityDistrict($result);
        }

        if (isset($portion)) {
            $result = array_merge($result ?: [], $portion);
        }

        // Parse extra fields
        if ($extra = array_filter($this->config['extra'] ?? [])) {
            $extra = $this->matchExtra($result['address'] ?? $address, $extra);
            $result = $result ? array_merge($result, $extra) : $extra;
        }

        return $result;
    }

    /**
     * 切割并解析带格式格式的地址（如：换行、空格分割，且带识别关键字），如果地址是无固定格式的，则不处理并返回null
     * @param string $address
     * @return array
     */
    protected function extractViaKeywords(string &$address): ?array
    {
        if (strpos($address, "\n") !== false) { // substr_count($address, "\n") > 1
            $array = array_filter(explode("\n", $address));
        } elseif (strpos($address, ' ') !== false) {
            $array = array_filter(explode(' ', $address));
        }

        if (!isset($array)) {
            return null;
        }
        // 多维数组转一维数组
        // array_walk_recursive($this->config['keywords'], function ($item) use (&$keywords) { $keywords[] = $item; });
        $extra = array_filter($this->config['extra'] ?? []);

        foreach ($array as $key => &$item) {
            if (count($its = preg_split('/[:：]/u', $item, 2)) > 1) {
                $item = end($its);
            }

            foreach ($this->config['keywords']['person'] ?? [] as $words) {
                if (mb_strpos($item, $words) !== false) {
                    unset($array[$key]);

                    if (isset($extra['person'])) {
                        $result['person'] = trim(count($its) > 1 ? $item : str_replace($words, '', $item));
                        unset($this->config['extra']['person']);
                    }

                    break;
                }
            }

            foreach ($this->config['keywords']['mobile'] ?? [] as $words) {
                if (mb_strpos($item, $words) !== false) {
                    unset($array[$key]);

                    if (isset($extra['mobile'])) {
                        $mobile = count($its) > 1 ? $item : str_replace($words, '', $item);

                        $mobile = preg_replace('/0-|0?(\d{3})[ -](\d{4})[ -](\d{4})/', '$1$2$3', $mobile);

                        if (preg_match('/(?<!\d|\+)(?:\+?\d{2,}[ -])?1[0-9]{10}(?!\d)(?:-\d+)?|(?<!\d)(?:\d{3,4}\-)?\d{8}(?:\-\d+)?(?!\d)/', $mobile, $match)) {
                            $result['mobile'] = $match[0];
                            unset($this->config['extra']['mobile']);
                        }
                    }

                    break;
                }
            }
        }

        $address = implode(' ', $array);

        return $result ?? null;
    }

    /**
     * 解析省、市、区
     * @param string $address
     * @return array|false|string[]|null 提取失败返回null，校验失败返回false，解析成功返回数组
     * @internal 先尝试提取省、市、区，后校验提取结果。
     * Verbatim smart parse - 逐字逐句的
     */
    protected function parse(string $address)
    {
        $result = $this->extractIfRegular($address)
            ?: $this->extractViaRegex($address);

        $strict = $this->config['strict'] ?? false;

        if ($result && count($result) >= 4 && $strict) {
            $result = $this->correct(...array_values($result));
        }

        if (!$result && ($result = $this->extractReverseFuzzy($address)) && $strict) {
            $result = $this->correctReverse(...array_values($result));
        }

        if (!$result && $strict) {
            $result = $this->verbatimParse($address);
        }

        return $result;
    }

    /**
     * 规则的地址提取
     * @param string $address
     * @return array|null
     * @internal 规则的地址：即省、市、区由特殊符号分隔。
     * 省级地名最大字符长度：8 （新疆维吾尔自治区）
     * 市级地名最大字符长度：11（黔西南布依族苗族自治州、克孜勒苏柯尔克孜自治州）
     * Mysql _>
     * SELECT * FROM `areas` where level in (1, 2) and CHAR_LENGTH(`name`) >= 11;
     * 1) length()： 单位是字节，UTF8编码下一个汉字占三个字节，一个数字或字母占一个字节；GBK
     *    编码下一个汉字占两个字节，一个数字或字母占一个字节。
     * 2) char_length()：单位为字符，不管汉字还是数字或者是字母都算是一个字符。
     * 3) character_length(): 同 char_length
     */
    protected function extractIfRegular(string $address): ?array
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

        return array_combine(['province', 'city', 'district', 'address'], $result);
    }

    /**
     * 通过正则表达式提取
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
        if (!preg_match("#([\x{4e00}-\x{9fa5}]{2}省)[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{2,4}市)(.*)$#Uu", $address, $match)) {
            return null;
        }

        array_shift($match);

        $quString = array_pop($match); // end($match);

        if (preg_match("#^[^\x{4e00}-\x{9fa5}]*([\x{4e00}-\x{9fa5}]{1,12}(?:自治县|县|市|区|旗|镇))(.*)$#Uu", $quString, $match2)
            && (strcmp(mb_substr($match2[1], -1), '区') || !in_array(mb_substr($match2[1], -2, 1), $this->qu_interference_words, true))) {
            // array_shift($match2);
            // array_pop($match);
            // array_push($match, ...$match2);
            array_push($match, next($match2), $address);
        } else {
            //array_splice($match, 2, 0, '');
            array_push($match, null, $address);
        }

        return array_combine(['province', 'city', 'district', 'address'], $match);
    }

    /**
     * 逆向模糊提取
     * @param string $address
     * @return array|null 省，市，区，街道地址
     * @internal 反向查找：从区级 -> 市级 -> 省级
     * @version 2.1.3 兼容：福建省古田县城西街道汇诚国际19幢一单元202室  【县城被跳过】
     * @author pupuk<pujiexuan@gmail.com>, zifan<s443939412@163.com>
     */
    protected function extractReverseFuzzy($address): ?array
    {
        $province = $city = $district = null;
        $address = str_replace([' ', ','], '', $address); // '自治区', '自治州' --> '省', '州'
        $xianPos = $this->getXianPosition($address); // mb_strpos($address, '县');
        $quPos = $this->getQuPosition($address);     // mb_strpos($address, '区')
        $qiPos = mb_strpos($address, '旗');
        $refPos = floor((mb_strlen($address) / 3) * 2);

        if ($qiPos && $qiPos < $refPos && !$quPos && !$xianPos) {
            $district = mb_substr($address, $qiPos - 1, 2);
        } elseif ($quPos && $quPos < $refPos && (!$xianPos || $xianPos == $quPos + 1)) {
            if ($shiPos = mb_strrpos(mb_substr($address, 0, $quPos - 1), '市')) {
                /***************************/
                $district = mb_substr($address, $shiPos + 1, $quPos - $shiPos);
                $city = mb_substr($address, $shiPos - 2, 3);
                if (mb_strpos($city, '市') === 1) {
                    $city = mb_substr($address, $shiPos - 3, 3);
                } elseif ($shiPos = mb_strpos(mb_substr($address, 0, $shiPos - 1), '市')) {
                    $district = $city;
                    $city = mb_substr($address, $shiPos - 2, 3);
                }
                /***************************/
            } else {
                $district = $quPos === 1 ? mb_substr($address, 0, 2) : mb_substr($address, $quPos - 2, 3);
            }
        } elseif ($xianPos && $xianPos < $refPos) {
            if ($shiPos = mb_strrpos(mb_substr($address, 0, $xianPos - 1), '市')) {
                /***************************/
                $district = mb_substr($address, $shiPos + 1, $xianPos - $shiPos);
                $city = mb_substr($address, $shiPos - 2, 3);
                if (mb_strpos($city, '市') === 1) {
                    $city = mb_substr($address, $shiPos - 3, 3);
                } elseif ($shiPos = mb_strpos(mb_substr($address, 0, $shiPos - 1), '市')) {
                    $district = $city;
                    $city = mb_substr($address, $shiPos - 2, 3);
                }
                /***************************/
            } else {
                if ($zizhiXianPos = mb_strpos($address, '自治县')) {
                    //考虑形如【甘肃省东乡族自治县布楞沟村1号】的情况
                    $district = mb_substr($address, $zizhiXianPos - 3, 6);
                } else {
                    // @FIXME 两个字的县名：赵县，怎么办？
                    $district = $xianPos === 1 ? mb_substr($address, 0, 2) : mb_substr($address, $xianPos - 2, 3);
                }
            }
        }

        if (is_null($city)) {
            if (mb_substr_count($address, '市') >= 2) {
                $district = mb_substr($address, mb_strrpos($address, '市') - 2, 3);
                $city = mb_substr($address, mb_strpos($address, '市') - 2, 3);
                // $street = mb_substr($origin, mb_strrpos($address, '市') + 1);
            } elseif ($shiRPos = mb_strrpos($address, '市')) {
                $city = mb_substr($address, $shiRPos - 2, 3);
            } elseif ($menRPos = mb_strrpos($address, '盟')) {
                $city = mb_substr($address, $menRPos - 2, 3);
            } elseif ($zhouRPos = mb_strrpos($address, '州')) {
                $city = ($ziZhiZhouPos = mb_strrpos($address, '自治州')) !== false
                    ? mb_substr($address, $ziZhiZhouPos - 4, 7)
                    : ($zhouRPos === 1 ? mb_substr($address, 0, 2) : mb_substr($address, $zhouRPos - 2, 3));
            }
        }

        if ($shengPos = mb_strrpos($address, '自治区')) {
            $province = mb_substr($address, $shengPos - 3, 6);
        } elseif ($shengPos = mb_strrpos($address, '省')) {
            $province = mb_substr($address, $shengPos - 2, 3);
        }

        if ($province && !$city && !$district && $pos = mb_strpos($address, '县') ?: $pos = mb_strpos($address, '区')) {
            $district = mb_substr($address, $pos - 2, 3);
        }

        $result = compact('province', 'city', 'district');

        return array_filter($result) ? $result + ['address' => $address] : null;
    }

    protected function getXianPosition(string $address)
    {
        for ($i = 1; $i < mb_strlen($address); $i++) {
            if (!strcmp(mb_substr($address, $i, 1), '县') &&
                !in_array(mb_substr($address, $i + 1, 1), $this->xian_interference_words, true)) {
                return $i;
            }
        }

        return false;
    }

    /**
     * 排除“干扰区”并返回最准确区的 position
     *
     * @param string $address
     * @return false|int
     * @see strtok() 逐一分割字符串。在首次调用后，该函数仅需要 split 参数，这是因为它清楚自己在当前字符串中所在的位置
     */
    protected function getQuPosition(string $address)
    {
        for ($i = 1; $i < mb_strlen($address); $i++) {
            if (!strcmp(mb_substr($address, $i, 1), '区') && // $address[$i] 会乱码
                !in_array(mb_substr($address, $i - 1, 1), $this->qu_interference_words, true)) { // 开发区
                return $i;
            }
        }

        return false;
    }

    /**
     * 检查并校正省、市、区地址名称
     *
     * @param string|null $province
     * @param string|null $city
     * @param string|null $district
     * @param string $address
     * @return array|false
     * @internal “省级地址”提取成功的前提下进行校正
     */
    protected function correct(?string $province, ?string $city, ?string $district, $address = '')
    {
        $areas = $this->getAreas();

        $provinceArea = $this->lookup($areas, $province);

        if (is_null($provinceArea)) {
            return false;
        }

        $cityArea = $this->matchCity($provinceArea['children'] ?? [], $city, $districtArea);

        if (is_null($cityArea)) {
            return false;
        }

        if (!isset($districtArea)) {
            $districtArea = $this->matchDistrict($cityArea['children'] ?? [], $district, $address);
        }

        return [
            'province' => $provinceArea['name'],
            'province_id' => $provinceArea['id'],
            'city' => $cityArea['name'],
            'city_id' => $cityArea['id'],
            'district' => $districtArea['name'] ?? null,
            'district_id' => $districtArea['id'] ?? null,
            'address' => $address
        ];
    }

    /**
     * @param array|\ArrayAccess $areas
     * @param string $target
     * @return array|null
     */
    protected function lookup($areas, string $target): ?array
    {
        foreach ($areas as $area) {
            if (mb_strrpos($area['name'], $target) !== false) {
                return $area;
            }
        }

        return null;
    }

    /**
     * 在指定“省级”区域下查找市级记录
     * @param array $cities
     * @param string $target
     * @param array|null $districtArea
     * @return array|null
     *
     * @internal 市级地名全国唯一：（以下查询空记录）
     * SELECT * FROM `areas` WHERE  level = 2 GROUP BY `name` HAVING COUNT(id) > 1;
     */
    protected function matchCity(array $cities, string $target, &$districtArea): ?array
    {
        $districtArea = null; // 该行必须存在

        if ($city = $this->lookup($cities, $target)) {
            return $city;
        }

        // Can recursive lookup
        foreach ($cities as $city) {
            if ($districtArea = $this->lookup($city['children'] ?? [], $target)) {
                return $city;
            }
        }

        return null;
    }

    /**
     * 在指定“市级”区域下查找区级记录
     *
     * @param array $districts
     * @param string|null $target
     * @param string $address
     * @return array|null;
     */
    protected function matchDistrict(array $districts, ?string $target, string $address): ?array
    {
        if ($target && !is_null($area = $this->lookup($districts, $target))) { // @accuracy
            return $area;
        }

        foreach ($districts as $area) {
            $possibleDistrict = mb_strlen($area['name']) > 2
                ? mb_substr($area['name'], 0, -1)
                : $area['name'];

            if (mb_strrpos($address, $possibleDistrict) !== false) {
                return $area;
            }
        }

        return null;
    }


    /**
     * 检查并校正省、市、区地址名称
     *
     * @param string|null $province
     * @param string|null $city
     * @param string|null $district
     * @param string $address
     * @return array|false 省，市，区：['province' => 'xxx', 'city' => 'yyy', 'district' => 'zzz', 'address' => 'aaa']
     */
    protected function correctReverse(?string $province, ?string $city, ?string $district, string $address = '')
    {
        $areas = $this->getAreas();

        if ($district) {
            foreach ($areas as $provinceArea) {
                foreach ($provinceArea['children'] ?? [] as $cityArea) {
                    $districtArea = $this->lookup($cityArea['children'] ?? [], $district);

                    if ($districtArea) {// @accuracy && (!$city || mb_strpos($cityArea['name'], $city) !== false)
                        $results[] = [
                            'province' => $provinceArea['name'],
                            'province_id' => $provinceArea['id'],
                            'city' => $cityArea['name'],
                            'city_id' => $cityArea['id'],
                            'district' => $districtArea['name'],
                            'district_id' => $districtArea['id']
                        ];
                    }
                }
            }
        }

        if ($city) {
            foreach ($areas as $provinceArea) {
                $cityArea = $this->lookup($provinceArea['children'] ?? [], $city);

                if ($cityArea) {
                    $districtArea = $this->matchDistrict($cityArea['children'] ?? [], $district, $address);

                    $results[] = [
                        'province' => $provinceArea['name'],
                        'province_id' => $provinceArea['id'],
                        'city' => $cityArea['name'],
                        'city_id' => $cityArea['id'],
                        'district' => $districtArea['name'] ?? null,
                        'district_id' => $districtArea['id'] ?? null
                    ];
                }
            }

            if (empty($results) && strcmp(mb_substr($city, -1), '市') === 0) { // 提取到的“市”可能是一个县级市
                return $this->correctReverse($province, '', $city, $address);
            }
        }

        if (empty($results)) {
            return false;
        }

        if (count($results) > 1) {
            foreach ($results as $result) {
                if ($province &&
                    mb_strpos($result['province'], $province) !== false ||
                    mb_strpos($address, $result['province']) !== false) {
                    goto end;
                }
            }

            foreach ($results as &$result) {
                $result['weight'] = $district ?
                    $this->calculateWeight(
                        mb_strstr($address, $district, true) . $district,
                        $result['province'] . $result['city'] . $result['district']
                    ) : $this->calculateWeight(
                        mb_strstr($address, $city, true) . $city,
                        $result['province'] . $result['city']
                    );
            }

            $results = $this->sortByWeightAndSlice($results);
        }

        $result = current($results);

        if ($province && mb_strpos($result['province'], $province) === false) {
            return false;
        }

        unset($result['weight']);

        end:
        return $result + ['address' => $address];
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
     * 地址查询 权重计算
     * @param string $real
     * @param string $possible
     * @return float
     * @internal mb_str_split required PHP >= 7.4，但"symfony/polyfill-mbstring": "^1.13"扩展定义了该函数
     */
    protected function calculateWeight(string $real, string $possible): float
    {
        // 单字符重叠权重计算
        $intersectArr = array_intersect(
            $realArr = mb_str_split($real), $possibleArr = mb_str_split($possible)
        );
        $single = floatval(
            round(($radioCount = count($intersectArr)) / ($realCount = count($realArr)), 2)
            * $radioCount * 2
        );
        // 双字符重叠权重计算
        $realDoubleArr = $possibleDoubleArr = [];

        for ($i = 0; $i <= $realCount - 2; $i++)
            $realDoubleArr[] = $realArr[$i] . $realArr[$i + 1];

        for ($i = 0; $i <= count($possibleArr) - 2; $i++)
            $possibleDoubleArr[] = $possibleArr[$i] . $possibleArr[$i + 1];

        $intersectDoubleArr = array_intersect($realDoubleArr, $possibleDoubleArr);

        $double = floatval(
            round($radioCount = count($intersectDoubleArr) / count($realDoubleArr), 2)
            * $radioCount * 3
        );

        return round($single + $double, 2);
    }

    /**
     * 根据权重进行排序
     * @param array $array
     * @param int $length
     * @return array
     */
    protected function sortByWeightAndSlice(array $array, int $length = 10): array
    {
        $weights = array_column($array, 'weight');
        // 疑问：第一个参数明明是传址引用，直接array_column($array, 'weight')怎么不报错？
        array_multisort($weights, SORT_DESC, $array);

        return array_slice($array, 0, $length);
    }

    /**
     * 清理下标为address的元素，替换消除省市区只保留详细地址
     *
     * @param array $result
     */
    protected function clearUpProvinceCityDistrict(array &$result)
    {
        /** @see \Illuminate\Support\Arr::only() 模仿实现 */
        $search = array_filter(array_intersect_key($result, array_flip(['province', 'city', 'district'])));

        ksort($search);

        // array_push($search, mb_substr($result['province'], 0, mb_strlen($result['province']) - 1), $city, $district);

        foreach ($search as $val) {
            if (2 < mb_strlen($val)) {
                $search[] = mb_substr($val, 0, -1);
            }
        }

        $detail = str_replace($search, ' ', $result['address']);

        $result['address'] = ltrim($detail, ' ');
    }

    /**
     * @param string $address
     * @return array|false
     * @since v2.1.3
     */
    protected function verbatimParse(string $address)
    {
        $areas = $this->getAreas();

        foreach ($areas as $area) {
            $needle = preg_match('#[\x{4e00}-\x{9fa5}]+(?=省$|自治区$|市$)#u', $area['name'], $matches)
                ? $matches[0]
                : $area['name'];

            if (false !== $provPos = mb_strrpos($address, $needle)) {
                $provinceArea = $area;
                break;
            }
        }

        $address = mb_substr($address, $provPos + mb_strlen($needle));

        if (isset($provinceArea)) {
            foreach ($area['children'] as $area2) {
                $needle = mb_substr($area2['name'], 0, 2);

                if (false !== $cityPos = mb_strrpos($address, $needle)) {
                    $cityArea = $area2;
                    break;
                }
            }
        } else {
            foreach ($areas as $area) {
                foreach ($area['children'] as $area2) {
                    $needle = mb_strrpos($area2['name'], '自治州')
                        ? mb_substr($area2['name'], 0, -3)
                        : mb_substr($area2['name'], 0, 2);

                    if (false !== $cityPos = mb_strrpos($address, $needle)) {
                        $cityArea = $area2;
                        $provinceArea = $area;
                        break;
                    }
                }
            }
        }

        if (empty($cityArea)) {
            return false;
        }

        $address = mb_substr($address, $cityPos + 2);

        foreach ($cityArea['children'] as $area) {
            $needle = mb_strrpos($area['name'], '自治县')
                ? mb_substr($area['name'], 0, -3)
                : mb_substr($area['name'], 0, 2);

            if (false !== mb_strrpos($address, $needle)) {
                $districtArea = $area;
                break;
            }
        }

        return [
            'province' => $provinceArea['name'],
            'province_id' => $provinceArea['id'],
            'city' => $cityArea['name'],
            'city_id' => $cityArea['id'],
            'district' => $districtArea['name'] ?? null,
            'district_id' => $districtArea['id'] ?? null,
            'address' => $address
        ];
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

        // 提取联系方式、姓名
        if (isset($extra['mobile']) || isset($extra['person'])) {
            // 去除手机号码中的短横线 如136-3333-6666 主要针对苹果手机
            $string = preg_replace('/0-|0?(\d{3})[ -](\d{4})[ -](\d{4})/', '$1$2$3', $string);

            if (preg_match('/(?<!\d|\+)(?:\+?\d{2,}[ -])?1[0-9]{10}(?!\d)(?:-\d+)?|(?<!\d)(?:\d{3,4}\-)?\d{8}(?:\-\d+)?(?!\d)/', $string, $match)) {
                if (isset($extra['person'])) {
                    $compose['person'] = $this->getPersonClosestToMobile($string, $match[0]);
                }

                if (isset($extra['mobile'])) {
                    $compose['mobile'] = $match[0];
                    $string = str_replace($match[0], ' ', $string);
                }
            }
        }

        // 提取邮编
        if (isset($extra['postcode']) &&
            preg_match('/(?<!\d)\d{6}(?!\d)/U', $string, $match)) {
            $compose['postcode'] = $match[0];
            $string = str_replace($match[0], ' ', $string);
        }

        // 提取姓名
        if (isset($extra['person']) && !isset($compose['person'])) {
            $compose['person'] = $this->getShortestSubstring($string);
        }

        $compose['address'] = str_replace(' ', '', $string);

        return $compose;
    }

    /**
     * 获取最靠近“手机号”的子串（大概率是姓名）
     * @param string $string
     * @param string $mobile
     * @return string|null
     * @internal 姓名通常位于手机号前面或后面
     */
    protected function getPersonClosestToMobile(string &$string, string $mobile): ?string
    {
        // mb_strstr($string, $mobile, true), mb_substr(mb_strstr($string, $mobile), mb_strlen($mobile) + 1);
        $array = explode($mobile, $string, 2);

        foreach ($array as $item) {
            if ($this->validatePerson($item = trim($item))) {
                $person = str_replace(['.', '，', ','], '', $item);
            }
        }

        if (!isset($person)) {
            foreach ($array as $index => $item) {
                if (count($array) > 1 && $index === 0) {
                    $item = preg_split('/[\s\.，,]+/u', $item);
                } else {
                    $item = preg_split('/[\s\.，,]+/u', $item, 1);
                }

                if ($this->validatePerson($item = end($item))) {
                    $person = $item;
                }
            }
        }

        if (isset($person)) {
            $string = str_replace($person, ' ', $string);
        }

        return $person ?? null;
    }

    protected function validatePerson(string $string): bool
    {
        $length = mb_strlen($string);

        return $length > 1 && $length < 8; // '/[\x{4e00}-\x{9fa5}A-Za-z\d_]+/Uu' (?=:|：)
    }

    /**
     * 通过概率提取最短字串（姓名）
     * @param string $string
     * @return string|null
     * @internal 按照空白符或其他特定字符切分后，片面的判断最短的为姓名（不是基于自然语言分析，只是采取统计学上高概率的方案）
     */
    protected function getShortestSubstring(string &$string): ?string
    {
        /*if (preg_match('/(?:[一二三四五六七八九\d+](?:室|单元|号楼|期|弄|号|幢|栋)\d*)+ *([^一二三四五六七八九 室期弄号幢栋元号楼铺口_#！!@（\(]{2,8})/Uu', $string, $match)) {preg_match('/(?:[一二三四五六七八九\d+](?:室|单元|号楼|期|弄|号|幢|栋)\d*)+ *([^一二三四五六七八九 室期弄号幢栋|单元|号楼|商铺|档口|A-Za-z0-9_#！!@（\(]{2,10}) *(?:\d{11})?$/Uu', $string, $match)) {
            $compose['person'] = $match[1];
        }*/

        foreach (preg_split('/[\s\.，,]+/u', $string) as $item) {
            $length = mb_strlen($item);

            if ($this->validatePerson($item) && (!isset($shortest) || $shortest > $length)) {
                $shortest = $length;
                $person = $item;
            }
        }

        if (isset($person)) {
            $string = str_replace($person, ' ', $string);
        }

        return $person ?? null;
    }
}
